"""Test WordPress REST API authentication."""

import os

import requests


def main() -> None:
    """Test WordPress REST API authentication."""

    wp_url = os.environ["WP_URL"].rstrip("/")
    username = os.environ["WP_USERNAME"]
    app_password = os.environ["WP_APP_PASSWORD"]

    url = f"{wp_url}/wp-json/wp/v2/users/me"

    response = requests.get(
        url,
        auth=(username, app_password),
        timeout=30,
    )

    response.raise_for_status()

    data = response.json()

    print("WordPress authentication succeeded.")
    print()
    print(f"id:       {data['id']}")
    print(f"name:     {data['name']}")
    print(f"username: {data['slug']}")


if __name__ == "__main__":
    main()