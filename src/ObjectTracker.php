<?php

/**
 * This file is part of the nexphant Framework.
 *
 * (c) nexphant <https://github.com/nexphant>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Nexphant\Server;

class ObjectTracker
{
    private \WeakMap $objects;
    private array $contexts = [];
    private int $tracked = 0;
    private int $released = 0;
    private int $contextOpened = 0;
    private int $contextClosed = 0;
    private bool $enabled;

    public function __construct(bool $enabled = false)
    {
        $this->objects = new \WeakMap();
        $this->enabled = $enabled;
    }

    public function openContext(string $id, string $type = 'request'): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->contexts[$id] = [
            'type' => $type,
            'opened_at' => microtime(true),
            'closed_at' => null,
        ];
        $this->contextOpened++;
    }

    public function closeContext(string $id): void
    {
        if (!$this->enabled) {
            return;
        }

        if (!isset($this->contexts[$id])) {
            return;
        }

        $this->contexts[$id]['closed_at'] = microtime(true);
        $this->contextClosed++;
    }

    public function track(object $object, string $type, string $owner = '', string $context = '', string $state = 'active'): void
    {
        if (!$this->enabled) {
            return;
        }

        if (!isset($this->objects[$object])) {
            $this->tracked++;
        }

        $this->objects[$object] = [
            'id' => spl_object_id($object),
            'class' => $object::class,
            'type' => $type,
            'owner' => $owner,
            'context' => $context,
            'state' => $state,
            'tracked_at' => microtime(true),
            'updated_at' => microtime(true),
        ];
    }

    public function update(object $object, array $changes): void
    {
        if (!$this->enabled) {
            return;
        }

        if (!isset($this->objects[$object])) {
            return;
        }

        $meta = $this->objects[$object];
        foreach ($changes as $key => $value) {
            $meta[$key] = $value;
        }
        $meta['updated_at'] = microtime(true);
        $this->objects[$object] = $meta;
    }

    public function release(object $object, string $state = 'released'): void
    {
        if (!$this->enabled) {
            return;
        }

        if (!isset($this->objects[$object])) {
            return;
        }

        $this->released++;
        $this->update($object, [
            'owner' => '',
            'context' => '',
            'state' => $state,
        ]);
    }

    public function stats(): array
    {
        if (!$this->enabled) {
            return [
                'enabled' => false,
                'active' => 0,
                'tracked_total' => 0,
                'released_total' => 0,
                'retained' => 0,
                'released_alive' => 0,
                'by_type' => [],
                'by_state' => [],
                'contexts' => [
                    'open' => 0,
                    'stale_closed' => 0,
                    'opened_total' => 0,
                    'closed_total' => 0,
                ],
            ];
        }

        $byType = [];
        $byState = [];
        $retained = 0;
        $releasedAlive = 0;
        $now = microtime(true);

        foreach ($this->objects as $object => $meta) {
            $type = (string) ($meta['type'] ?? 'unknown');
            $state = (string) ($meta['state'] ?? 'unknown');
            $byType[$type] = ($byType[$type] ?? 0) + 1;
            $byState[$state] = ($byState[$state] ?? 0) + 1;

            $context = (string) ($meta['context'] ?? '');
            if ($context !== '' && isset($this->contexts[$context]) && $this->contexts[$context]['closed_at'] !== null) {
                $retained++;
            }
            if ($state === 'released' || $state === 'dropped') {
                $releasedAlive++;
            }
        }

        $openContexts = 0;
        $staleContexts = 0;
        foreach ($this->contexts as $context) {
            if ($context['closed_at'] === null) {
                $openContexts++;
                continue;
            }
            if ($now - (float) $context['closed_at'] > 60) {
                $staleContexts++;
            }
        }

        return [
            'enabled' => true,
            'active' => count($this->objects),
            'tracked_total' => $this->tracked,
            'released_total' => $this->released,
            'retained' => $retained,
            'released_alive' => $releasedAlive,
            'by_type' => $byType,
            'by_state' => $byState,
            'contexts' => [
                'open' => $openContexts,
                'stale_closed' => $staleContexts,
                'opened_total' => $this->contextOpened,
                'closed_total' => $this->contextClosed,
            ],
        ];
    }

    public function cleanupContexts(int $maxClosed = 4096): void
    {
        if (!$this->enabled) {
            return;
        }

        if (count($this->contexts) <= $maxClosed) {
            return;
        }

        $this->contexts = array_slice($this->contexts, -$maxClosed, null, true);
    }
}
