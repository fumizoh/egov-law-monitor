"""Read-only WordPress watch diagnostics; never print emails or send mail."""
import argparse
from pathlib import Path
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "src"))
from watch import service


def positive_user(value):
    if not value.isdecimal() or int(value) < 1:
        raise argparse.ArgumentTypeError("user-id must be a positive integer")
    return int(value)


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--user-id", type=positive_user, help="filter after reading the existing internal API")
    args = parser.parse_args(argv)
    try:
        users = [user for user in service.get_watch_users() if args.user_id is None or user.user_id == args.user_id]
        print(f"matched_users: {len(users)}; inspection only, no messages sent")
        for number, user in enumerate(users, 1):
            print(f"user_index: {number}; plan: {user.plan}; notifications: {'ON' if user.notifications else 'OFF'}; watch_count: {len(user.watches)}")
        return 0
    except Exception as exc:
        print(f"ERROR: {type(exc).__name__} (details suppressed)", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
