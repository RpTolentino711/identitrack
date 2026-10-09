import pandas as pd

df = pd.read_csv(r"c:\xampp\htdocs\identitrack\admin\AI\softeng_2-master\server\modle\Student-Discipline-Office-Violations-Dataset.csv")
print("Total rows:", len(df))
print("Columns:", df.columns.tolist())
print("\nUnique categories:")
print(df['Category'].value_counts())
print("\nUnique Number of Offense:")
print(df['Number of Offense'].value_counts())
print("\nTop 15 Sanctions:")
print(df['Sanction'].value_counts().head(15))
