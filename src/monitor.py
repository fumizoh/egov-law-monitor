from download import download_update

def main():

    response = download_update()

    print(response.status_code)

    print(response.headers["Content-Type"])