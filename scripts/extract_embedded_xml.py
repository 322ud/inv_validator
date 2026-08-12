#!/usr/bin/env python3

"""Extract one invoice XML attachment from a PDF into a fixed output path."""

from __future__ import annotations

import os
import sys

MAX_ATTACHMENTS = 32
MAX_XML_BYTES = 5 * 1024 * 1024
PREFERRED_NAMES = {"factur-x.xml", "zugferd-invoice.xml"}


def looks_like_xml(content: bytes) -> bool:
    stripped = content.lstrip(b"\xef\xbb\xbf\x00\t\n\r ")
    return stripped.startswith(b"<")


def main() -> int:
    if len(sys.argv) != 3:
        return 15

    try:
        import pymupdf
    except ImportError:
        return 20

    input_path, output_path = sys.argv[1:]

    try:
        with pymupdf.open(input_path) as document:
            if not document.is_pdf:
                return 15
            if document.needs_pass:
                return 14

            count = document.embfile_count()
            if count > MAX_ATTACHMENTS:
                return 13

            preferred: list[bytes] = []
            other: list[bytes] = []

            for index in range(count):
                info = document.embfile_info(index)
                declared_size = info.get("size")
                if isinstance(declared_size, int) and declared_size > MAX_XML_BYTES:
                    return 12

                content = document.embfile_get(index)
                if len(content) > MAX_XML_BYTES:
                    return 12
                if not looks_like_xml(content):
                    continue

                names = {
                    str(info.get(key, "")).lower()
                    for key in ("name", "filename", "ufilename")
                }
                if names & PREFERRED_NAMES:
                    preferred.append(content)
                else:
                    other.append(content)

            candidates = preferred if preferred else other
            if not candidates:
                return 10
            if len(candidates) != 1:
                return 11

            descriptor = os.open(output_path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
            with os.fdopen(descriptor, "wb") as output:
                output.write(candidates[0])
    except (OSError, RuntimeError, ValueError):
        return 15

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
