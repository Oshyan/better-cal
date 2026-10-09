"""Merge KEY=VALUE settings from stdin into an existing .env, in place.

Run as root on the server by scripts/push-settings.sh; never part of a deploy.
Each setting given replaces every line for that key, or is appended; every
other line is left exactly as it was. Values arrive on stdin and are never
printed: the report names keys only.

Safety, since the .env sits in a directory the app user owns:
- The file is opened with O_NOFOLLOW and must be a regular file, so a symlink
  swapped in for it can't redirect the write elsewhere.
- It's rewritten through the same descriptor (truncate and write), so its
  inode, owner and mode stay as provisioned.
- A copy goes first to a root-only backup directory, created exclusively.

Usage: python3 merge-env.py <path to .env> <label for the backup name>
Environment: BC_ENV_BACKUP_DIR overrides the backup directory (tests).
"""

import os
import re
import stat
import sys
import time

KEY = re.compile(r'^[A-Z][A-Z0-9_]*$')


def main() -> int:
    if len(sys.argv) != 3:
        print('usage: merge-env.py <.env path> <label>', file=sys.stderr)
        return 2
    path, label = sys.argv[1], sys.argv[2]
    if not re.fullmatch(r'[a-z0-9-]{1,32}', label):
        print('label must be lowercase letters, digits or dashes', file=sys.stderr)
        return 2

    pairs = []
    for line in sys.stdin.read().split('\n'):
        if line == '':
            continue
        key, sep, value = line.partition('=')
        if sep != '=' or not KEY.match(key) or '\r' in value:
            print('refused: a setting line is not KEY=VALUE', file=sys.stderr)
            return 2
        pairs.append((key, value))
    if not pairs:
        print('nothing to merge', file=sys.stderr)
        return 2

    try:
        fd = os.open(path, os.O_RDWR | os.O_NOFOLLOW)
    except OSError as e:
        print(f'cannot open {path} without following links: {e.strerror}', file=sys.stderr)
        return 1
    try:
        if not stat.S_ISREG(os.fstat(fd).st_mode):
            print(f'{path} is not a regular file', file=sys.stderr)
            return 1
        chunks = []
        while True:
            chunk = os.read(fd, 65536)
            if not chunk:
                break
            chunks.append(chunk)
        old = b''.join(chunks)

        backup_dir = os.environ.get('BC_ENV_BACKUP_DIR', '/root/bettercal-env-backups')
        os.makedirs(backup_dir, mode=0o700, exist_ok=True)
        os.chmod(backup_dir, 0o700)
        stamp = time.strftime('%Y%m%dT%H%M%SZ', time.gmtime())
        backup = os.path.join(backup_dir, f'{label}-{stamp}-{os.getpid()}.env')
        bfd = os.open(backup, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        try:
            os.write(bfd, old)
        finally:
            os.close(bfd)

        lines = old.decode('utf-8').split('\n')
        trailing = lines and lines[-1] == ''
        if trailing:
            lines.pop()
        report = []
        for key, value in pairs:
            hits = [i for i, l in enumerate(lines) if l.startswith(key + '=')]
            if not hits:
                lines.append(f'{key}={value}')
                report.append(f'added {key}')
            elif all(lines[i] == f'{key}={value}' for i in hits):
                report.append(f'unchanged {key}')
            else:
                for i in hits:
                    lines[i] = f'{key}={value}'
                report.append(f'updated {key}' + (f' ({len(hits)} lines)' if len(hits) > 1 else ''))
        new = ('\n'.join(lines) + '\n').encode('utf-8')

        if new != old:
            os.lseek(fd, 0, os.SEEK_SET)
            os.ftruncate(fd, 0)
            view = memoryview(new)
            while view:
                written = os.write(fd, view)
                view = view[written:]
            os.fsync(fd)
    finally:
        os.close(fd)

    for line in report:
        print(line)
    print(f'backup: {backup}')
    return 0


if __name__ == '__main__':
    sys.exit(main())
