import json
import joblib
import os
import xgboost as xgb

base_dir = os.path.dirname(os.path.abspath(__file__))
server_dir = os.path.abspath('admin/AI/softeng_2-master/server')
model_dir = os.path.join(server_dir, 'modle')

xgb_file = os.path.join(model_dir, 'sanction_xgb_model.json')
le_file = os.path.join(model_dir, 'label_encoder.pkl')
tfidf_file = os.path.join(model_dir, 'tfidf_vectorizer.pkl')

print("Loading model files...")
model_json = json.loads(open(xgb_file, 'r', encoding='utf-8').read())
trees = model_json['learner']['gradient_booster']['model']['trees']
tree_info = model_json['learner']['gradient_booster']['model']['tree_info']

le = joblib.load(le_file)
labels = [str(c) for c in le.classes_]

tfidf = joblib.load(tfidf_file)
vocab = {str(k): int(v) for k, v in tfidf.vocabulary_.items()}
idf = [float(val) for val in tfidf.idf_]

compiled_trees = []
for tree, c_idx in zip(trees, tree_info):
    compiled_trees.append({
        "class": int(c_idx),
        "lefts": tree['left_children'],
        "rights": tree['right_children'],
        "splits": tree['split_indices'],
        "conds": tree['split_conditions'],
        "weights": tree['base_weights'],
        "defaults": tree['default_left']
    })

compiled_data = {
    "num_classes": len(labels),
    "labels": labels,
    "vocab": vocab,
    "idf": idf,
    "trees": compiled_trees
}

out_file = os.path.join(model_dir, 'softeng_2_compiled_model.json')
with open(out_file, 'w', encoding='utf-8') as f:
    json.dump(compiled_data, f, separators=(',', ':'))

file_size_mb = os.path.getsize(out_file) / (1024 * 1024)
print(f"SUCCESS! Exact compiled model written to {out_file} ({file_size_mb:.2f} MB)")
