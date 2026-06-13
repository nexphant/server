#include <stddef.h>
#include <strings.h>

int nexph_find_header_end(const char *buffer, size_t length, size_t offset)
{
    if (buffer == 0 || length < 4 || offset >= length) {
        return -1;
    }
    for (size_t i = offset; i + 3 < length; i++) {
        if (buffer[i] == '\r' && buffer[i + 1] == '\n' && buffer[i + 2] == '\r' && buffer[i + 3] == '\n') {
            return (int) i;
        }
    }
    return -1;
}

int nexph_has_connection_close(const char *buffer, size_t length)
{
    const char needle[] = "Connection: close";
    const size_t needle_len = sizeof(needle) - 1;
    if (buffer == 0 || length < needle_len) {
        return 0;
    }
    for (size_t i = 0; i + needle_len <= length; i++) {
        if (strncasecmp(buffer + i, needle, needle_len) == 0) {
            return 1;
        }
    }
    return 0;
}
