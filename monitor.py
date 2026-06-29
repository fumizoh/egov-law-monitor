import requests

# e-Gov法令API Version2
url = "https://laws.e-gov.go.jp/api/2/laws"

print("APIへ接続しています...")

response = requests.get(url)

print(f"ステータスコード: {response.status_code}")

if response.status_code == 200:
    print("接続成功！")
    print(response.text[:500])   # 最初の500文字だけ表示
else:
    print("接続失敗")
