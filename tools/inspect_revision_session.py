"""Inspect Revision API session behavior."""

from pprint import pprint

import requests

LAW_ID = "423AC0000000081"

LAW_URL = f"https://laws.e-gov.go.jp/law/{LAW_ID}"
API_URL = (
    "https://laws.e-gov.go.jp/internal-api/"
    "SelectLawRevisionData.json"
)


def main():
    session = requests.Session()

    headers = {
        "User-Agent": (
            "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
            "AppleWebKit/537.36 (KHTML, like Gecko) "
            "Chrome/151.0.0.0 Safari/537.36"
        ),
        "Accept": "application/json, text/plain, */*",
    }

    print("=== STEP 1: GET law page ===")

    response = session.get(
        LAW_URL,
        headers=headers,
        timeout=30,
    )

    print(response.status_code)
    print(response.url)

    print("\nCookies:")
    pprint(session.cookies.get_dict())

    print("\n=== STEP 2: POST Revision API ===")

    headers |= {
        "Content-Type": "application/json",
        "Origin": "https://laws.e-gov.go.jp",
        "Referer": LAW_URL,
    }

    payload = {
        "law_id": LAW_ID,
    }

    print("=== LOOP ===")

    for i in range(100):
        response = session.post(
            API_URL,
            json={"law_id": LAW_ID},
            headers=headers,
            timeout=30,
        )

        if response.url.endswith("/sorry/404-notfound.html"):
            print(f"Failed at {i + 1}")

            print("Waiting 5 minutes...")
            import time
            time.sleep(300)

            print("Retry...")

            response = session.post(
                API_URL,
                json={"law_id": LAW_ID},
                headers=headers,
                timeout=30,
            )

            print("Status:", response.status_code)
            print("URL:", response.url)

            break


    '''
    for i in range(100):
        response = session.post(
            API_URL,
            json=payload,
            headers=headers,
            timeout=30,
        )

        if response.status_code != 200:
            print(i)
            print(response.status_code)
            print(response.headers)
            break

        try:
            response.json()
        except Exception:
            print(i)
            print(response.url)
            break
    '''

    '''
    print("Status:", response.status_code)
    print("URL:", response.url)
    print("History:", response.history)

    print("\nHeaders:")
    pprint(dict(response.headers))

    print("\nCookies:")
    pprint(session.cookies.get_dict())

    print("\nBody (first 300 chars):")
    print(response.text[:300])

    try:
        data = response.json()
        print("\nJSON OK")
        print(data.keys())
    except Exception as e:
        print("\nJSON ERROR")
        print(type(e).__name__, e)
    '''


if __name__ == "__main__":
    main()