import urllib.request
import json

url = "http://127.0.0.1:5000/predict"
payload = {
    "description": "Caught cheating by the prof",
    "category": "Automatic Major Offenses",
    "violation": "Cheating or academic dishonesty, in online or face-to-face settings, before or during an examination.",
    "number_of_offense": "2nd Offense"
}

req = urllib.request.Request(
    url,
    data=json.dumps(payload).encode("utf-8"),
    headers={"Content-Type": "application/json"}
)

try:
    with urllib.request.urlopen(req) as resp:
        print("STATUS:", resp.status)
        print("BODY:", resp.read().decode("utf-8"))
except Exception as e:
    print("ERROR:", e)
    if hasattr(e, "read"):
        print("ERR BODY:", e.read().decode("utf-8"))
