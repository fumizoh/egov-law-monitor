import requests

url = "https://laws.e-gov.go.jp/update/"

response = requests.get(url)

print(response.status_code)
print(response.text[:500])
