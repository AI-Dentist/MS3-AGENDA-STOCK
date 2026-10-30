"""
Machine Learning Model Training Pipeline for Dental Clinic MS3.
Implements:
1. Supervised Learning: Random Forest Classifier for No-Show (Inasistencia) Prediction.
2. Unsupervised Learning: K-Means Clustering for Patient Behavioral Segmentation.
3. Unsupervised Learning: K-Means Clustering for Stock / Insumo ABC Rotation Analysis.
"""

import os
import joblib
import numpy as np
import pandas as pd
from sklearn.pipeline import Pipeline
from sklearn.preprocessing import StandardScaler
from sklearn.ensemble import RandomForestClassifier
from sklearn.cluster import KMeans
from sklearn.model_selection import train_test_split
from sklearn.metrics import accuracy_score, classification_report

MODELS_DIR = os.path.join(os.path.dirname(__file__), "models")
os.makedirs(MODELS_DIR, exist_ok=True)


def train_no_show_model():
    print("[1/3] Training Supervised No-Show Classification Model...")
    np.random.seed(42)
    n_samples = 1200

    edad = np.random.randint(18, 75, n_samples)
    dias_anticipacion = np.random.randint(0, 45, n_samples)
    cancelaciones_previas = np.random.poisson(lam=0.8, size=n_samples)
    dia_semana = np.random.randint(1, 7, n_samples)  # 1=Mon, 6=Sat
    hora_cita = np.random.randint(8, 19, n_samples)  # 8:00 to 18:00
    tipo_tratamiento_riesgo = np.random.choice([0, 1, 2], p=[0.5, 0.35, 0.15], size=n_samples)

    # Probability of No-Show based on domain features
    # Higher cancelaciones, high lead days, Monday/Friday, high risk treatment increase probability
    logit = (
        -2.2
        + 0.05 * dias_anticipacion
        + 0.8 * cancelaciones_previas
        - 0.015 * edad
        + 0.35 * (dia_semana == 1).astype(int)
        + 0.30 * (dia_semana == 5).astype(int)
        + 0.40 * tipo_tratamiento_riesgo
    )
    prob_no_show = 1 / (1 + np.exp(-logit))
    inasistencia = (np.random.rand(n_samples) < prob_no_show).astype(int)

    df = pd.DataFrame({
        "edad": edad,
        "dias_anticipacion": dias_anticipacion,
        "cancelaciones_previas": cancelaciones_previas,
        "dia_semana": dia_semana,
        "hora_cita": hora_cita,
        "tipo_tratamiento_riesgo": tipo_tratamiento_riesgo,
        "inasistencia": inasistencia
    })

    X = df.drop(columns=["inasistencia"])
    y = df["inasistencia"]

    X_train, X_test, y_train, y_test = train_test_split(X, y, test_size=0.2, random_state=42, stratify=y)

    pipeline = Pipeline([
        ("scaler", StandardScaler()),
        ("classifier", RandomForestClassifier(
            n_estimators=100,
            max_depth=6,
            min_samples_split=4,
            random_state=42,
            class_weight="balanced"
        ))
    ])

    pipeline.fit(X_train, y_train)

    y_pred = pipeline.predict(X_test)
    acc = accuracy_score(y_test, y_pred)
    print(f"  -> No-Show Model Accuracy: {acc * 100:.2f}%")

    output_path = os.path.join(MODELS_DIR, "no_show_pipeline.joblib")
    joblib.dump(pipeline, output_path)
    print(f"  -> Model saved to: {output_path}")


def train_patient_clustering_model():
    print("[2/3] Training Unsupervised Patient Segmentation (K-Means)...")
    np.random.seed(42)
    n_patients = 600

    # Synthetic patient behavioral profiles
    # Cluster A: Loyal, frequent (20%)
    # Cluster B: Occasional / Preventive (55%)
    # Cluster C: High risk / Abandoners (25%)
    profile = np.random.choice([0, 1, 2], p=[0.25, 0.50, 0.25], size=n_patients)

    total_citas = np.zeros(n_patients)
    tasa_asistencia = np.zeros(n_patients)
    dias_promedio_entre_citas = np.zeros(n_patients)
    gasto_total_usd = np.zeros(n_patients)

    for i in range(n_patients):
        if profile[i] == 0:  # Frecuente
            total_citas[i] = np.random.randint(8, 25)
            tasa_asistencia[i] = np.random.uniform(0.85, 1.0)
            dias_promedio_entre_citas[i] = np.random.randint(20, 60)
            gasto_total_usd[i] = np.random.uniform(250.0, 1200.0)
        elif profile[i] == 1:  # Ocasional
            total_citas[i] = np.random.randint(2, 8)
            tasa_asistencia[i] = np.random.uniform(0.70, 0.90)
            dias_promedio_entre_citas[i] = np.random.randint(90, 240)
            gasto_total_usd[i] = np.random.uniform(80.0, 300.0)
        else:  # Riesgo
            total_citas[i] = np.random.randint(1, 4)
            tasa_asistencia[i] = np.random.uniform(0.20, 0.60)
            dias_promedio_entre_citas[i] = np.random.randint(180, 400)
            gasto_total_usd[i] = np.random.uniform(30.0, 120.0)

    df_patients = pd.DataFrame({
        "total_citas": total_citas,
        "tasa_asistencia": tasa_asistencia,
        "dias_promedio_entre_citas": dias_promedio_entre_citas,
        "gasto_total_usd": gasto_total_usd
    })

    pipeline = Pipeline([
        ("scaler", StandardScaler()),
        ("kmeans", KMeans(n_clusters=3, random_state=42, n_init=10))
    ])

    pipeline.fit(df_patients)

    # Sort cluster centers by semantic weight: total_citas * gasto_total_usd (descending)
    # Ensures Cluster 0 = Frecuente / Alto Compromiso, Cluster 1 = Ocasional, Cluster 2 = Riesgo de Deserción
    scaler = pipeline.named_steps["scaler"]
    kmeans = pipeline.named_steps["kmeans"]
    unscaled_centers = scaler.inverse_transform(kmeans.cluster_centers_)
    patient_scores = unscaled_centers[:, 0] * unscaled_centers[:, 3]
    sorted_patient_indices = np.argsort(-patient_scores)
    kmeans.cluster_centers_ = kmeans.cluster_centers_[sorted_patient_indices]

    output_path = os.path.join(MODELS_DIR, "patient_clustering_pipeline.joblib")
    joblib.dump(pipeline, output_path)
    print(f"  -> Patient Clustering Pipeline saved to: {output_path}")


def train_stock_clustering_model():
    print("[3/3] Training Unsupervised Inventory ABC / Rotation Clustering...")
    np.random.seed(42)
    n_items = 250

    consumo_mensual = np.random.exponential(scale=40, size=n_items) + 1
    costo_unitario = np.random.exponential(scale=25, size=n_items) + 2
    dias_reabastecimiento = np.random.randint(2, 30, size=n_items)

    df_stock = pd.DataFrame({
        "consumo_mensual": consumo_mensual,
        "costo_unitario": costo_unitario,
        "dias_reabastecimiento": dias_reabastecimiento
    })

    pipeline = Pipeline([
        ("scaler", StandardScaler()),
        ("kmeans", KMeans(n_clusters=3, random_state=42, n_init=10))
    ])

    pipeline.fit(df_stock)

    # Sort cluster centers by semantic weight: consumo_mensual * costo_unitario (descending)
    # Ensures Cluster 0 = Categoría A (Alto Valor), Cluster 1 = Categoría B, Cluster 2 = Categoría C
    scaler = pipeline.named_steps["scaler"]
    kmeans = pipeline.named_steps["kmeans"]
    unscaled_centers = scaler.inverse_transform(kmeans.cluster_centers_)
    stock_scores = unscaled_centers[:, 0] * unscaled_centers[:, 1]
    sorted_stock_indices = np.argsort(-stock_scores)
    kmeans.cluster_centers_ = kmeans.cluster_centers_[sorted_stock_indices]

    output_path = os.path.join(MODELS_DIR, "stock_clustering_pipeline.joblib")
    joblib.dump(pipeline, output_path)
    print(f"  -> Stock Clustering Pipeline saved to: {output_path}")


if __name__ == "__main__":
    train_no_show_model()
    train_patient_clustering_model()
    train_stock_clustering_model()
    print("All ML Pipelines trained and exported successfully!")
