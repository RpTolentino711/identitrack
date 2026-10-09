import sys
import os

sys.path.append(r'c:\xampp\htdocs\identitrack\admin\AI\softeng_2-master\server')

from server import predict, app
import json

with app.test_request_context('/predict', method='POST', data=json.dumps({
    "description": "Caught cheating by the prof",
    "category": "Automatic Major Offenses",
    "violation": "Cheating or academic dishonesty",
    "number_of_offense": "2nd Offense"
}), content_type='application/json'):
    try:
        res = predict()
        print(res.get_json())
    except Exception as e:
        import traceback
        traceback.print_exc()
