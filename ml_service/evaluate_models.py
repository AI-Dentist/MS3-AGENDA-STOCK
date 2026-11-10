"""
Offline Model Evaluation Script for Dental Clinic MS3 ML Pipelines.
Generates ml_service/models/model_card.json WITHOUT retraining models (S9).

Evaluates:
1. No-Show Supervised Model (RandomForest):
   - Held-out 20% test split: accuracy, precision, recall, f1_score, roc_auc.
2. Patient Behavioral Clustering (K-Means):
   - Silhouette score.
3. Stock / Insumo Rotation Clustering (K-Means):
   - Silhouette score.
"""

import os
import json
import datetime
import joblib
import numpy as np
import pandas as pd
from sklearn.model_selection import train_test_split
from sklearn.metrics import (
    accuracy_score,
    precision_score,
    recall_score,
    f1_score,
    roc_auc_score,
    silhouette_score,
)

MODELS_DIR = os.path.join(os.path.dirname(__file__), "models")
MODEL_CARD_PATH = os.path.join(MODELS_DIR, "model_card.json")

PATIENT_CLUSTER_NAMES = [
    "Frecuente y Puntual",
    "Riesgo de Abandono / Inconstante",
    "Atención Esporádica / Urgencias",
]

STOCK_CLUSTER_NAMES = [
    "Alta Rotación Crítica",
    "Consumo Estándar",
    "Baja Rotación",
]


def evaluate_no_show() -> dict:
    model_path = os.path.join(MODELS_DIR, "no_show_pipeline.joblib")
    if not os.path.exists(model_path):
        raise FileNotFoundError(f"Model not found at {model_path}")

    pipeline = joblib.load(model_path)

    # Regenerate identical synthetic data using training seed
    np.random.seed(42)
    n_samples = 2000

    edad = np.random.randint(18, 75, n_samples)
    dias_anticipacion = np.random.randint(0, 45, n_samples)
    cancelaciones_previas = np.random.poisson(lam=0.8, size=n_samples)
    dia_semana = np.random.randint(1, 7, n_samples)
    hora_cita = np.random.randint(8, 19, n_samples)
    banda_horaria = (hora_cita >= 13).astype(int)
    duracion_minutos = np.random.choice([20, 30, 45, 60, 90], p=[0.1, 0.4, 0.3, 0.15, 0.05], size=n_samples)
    tipo_tratamiento_riesgo = np.random.choice([0, 1, 2], p=[0.5, 0.35, 0.15], size=n_samples)

    logit = (
        -2.2
        + 0.05 * dias_anticipacion
        + 0.8 * cancelaciones_previas
        - 0.015 * edad
        + 0.35 * (dia_semana == 1).astype(int)
        + 0.30 * (dia_semana == 5).astype(int)
        + 0.40 * tipo_tratamiento_riesgo
        + 0.20 * banda_horaria
        + 0.003 * duracion_minutos
    )
    prob_no_show = 1 / (1 + np.exp(-logit))
    inasistencia = (np.random.rand(n_samples) < prob_no_show).astype(int)

    df = pd.DataFrame({
        "cancelaciones_previas": cancelaciones_previas,
        "dias_anticipacion": dias_anticipacion,
        "dia_semana": dia_semana,
        "hora_cita": hora_cita,
        "banda_horaria": banda_horaria,
        "duracion_minutos": duracion_minutos,
        "tipo_tratamiento_riesgo": tipo_tratamiento_riesgo,
        "edad": edad,
        "inasistencia": inasistencia,
    })

    X = df.drop(columns=["inasistencia"])
    y = df["inasistencia"]

    _, X_test, _, y_test = train_test_split(X, y, test_size=0.2, random_state=42, stratify=y)

    y_pred = pipeline.predict(X_test)
    y_prob = pipeline.predict_proba(X_test)[:, 1]

    metrics = {
        "accuracy": round(float(accuracy_score(y_test, y_pred)), 4),
        "precision": round(float(precision_score(y_test, y_pred, zero_division=0)), 4),
        "recall": round(float(recall_score(y_test, y_pred, zero_division=0)), 4),
        "f1_score": round(float(f1_score(y_test, y_pred, zero_division=0)), 4),
        "roc_auc": round(float(roc_auc_score(y_test, y_prob)), 4),
    }

    return {
        "tipo": "RandomForestClassifier",
        "pipeline": "StandardScaler + RandomForestClassifier(n_estimators=150, max_depth=7)",
        "muestras_totales": n_samples,
        "muestras_evaluacion": len(y_test),
        "split_test": 0.20,
        "metricas": metrics,
        "features": list(X.columns),
    }


def evaluate_patient_clustering() -> dict:
    model_path = os.path.join(MODELS_DIR, "patient_clustering_pipeline.joblib")
    if not os.path.exists(model_path):
        raise FileNotFoundError(f"Model not found at {model_path}")

    pipeline = joblib.load(model_path)

    np.random.seed(42)
    n_patients = 800

    profile = np.random.choice([0, 1, 2], p=[0.25, 0.50, 0.25], size=n_patients)
    total_citas = np.zeros(n_patients)
    tasa_asistencia = np.zeros(n_patients)
    dias_promedio_entre_citas = np.zeros(n_patients)
    gasto_total_estimado = np.zeros(n_patients)

    for i in range(n_patients):
        if profile[i] == 0:
            total_citas[i] = np.random.randint(8, 25)
            tasa_asistencia[i] = np.random.uniform(0.85, 1.0)
            dias_promedio_entre_citas[i] = np.random.randint(20, 60)
            gasto_total_estimado[i] = np.random.uniform(250.0, 1200.0)
        elif profile[i] == 1:
            total_citas[i] = np.random.randint(2, 8)
            tasa_asistencia[i] = np.random.uniform(0.40, 0.75)
            dias_promedio_entre_citas[i] = np.random.randint(90, 240)
            gasto_total_estimado[i] = np.random.uniform(80.0, 300.0)
        else:
            total_citas[i] = np.random.randint(1, 4)
            tasa_asistencia[i] = np.random.uniform(0.20, 0.50)
            dias_promedio_entre_citas[i] = np.random.randint(180, 400)
            gasto_total_estimado[i] = np.random.uniform(30.0, 120.0)

    df_patients = pd.DataFrame({
        "total_citas": total_citas,
        "tasa_asistencia": tasa_asistencia,
        "dias_promedio_entre_citas": dias_promedio_entre_citas,
        "gasto_total_estimado": gasto_total_estimado,
    })

    scaler = pipeline.named_steps["scaler"]
    scaled = scaler.transform(df_patients)
    labels = pipeline.named_steps["kmeans"].predict(scaled)

    sil_score = float(silhouette_score(scaled, labels))

    return {
        "tipo": "KMeans",
        "pipeline": "StandardScaler + KMeans(n_clusters=3)",
        "muestras_totales": n_patients,
        "k": 3,
        "clusters": PATIENT_CLUSTER_NAMES,
        "metricas": {
            "silhouette_score": round(sil_score, 4),
        },
        "features": list(df_patients.columns),
    }


def evaluate_stock_clustering() -> dict:
    model_path = os.path.join(MODELS_DIR, "stock_clustering_pipeline.joblib")
    if not os.path.exists(model_path):
        raise FileNotFoundError(f"Model not found at {model_path}")

    pipeline = joblib.load(model_path)

    np.random.seed(42)
    n_items = 350

    consumo_mensual = np.random.exponential(scale=40, size=n_items) + 1
    costo_unitario = np.random.exponential(scale=25, size=n_items) + 2
    dias_reabastecimiento = np.random.randint(2, 30, size=n_items)

    df_stock = pd.DataFrame({
        "consumo_mensual": consumo_mensual,
        "costo_unitario": costo_unitario,
        "dias_reabastecimiento": dias_reabastecimiento,
    })

    scaler = pipeline.named_steps["scaler"]
    scaled = scaler.transform(df_stock)
    labels = pipeline.named_steps["kmeans"].predict(scaled)

    sil_score = float(silhouette_score(scaled, labels))

    return {
        "tipo": "KMeans",
        "pipeline": "StandardScaler + KMeans(n_clusters=3)",
        "muestras_totales": n_items,
        "k": 3,
        "clusters": STOCK_CLUSTER_NAMES,
        "metricas": {
            "silhouette_score": round(sil_score, 4),
        },
        "features": list(df_stock.columns),
    }


def generate_model_card() -> dict:
    print("Evaluating models offline without retraining...")
    no_show = evaluate_no_show()
    patient_clustering = evaluate_patient_clustering()
    stock_clustering = evaluate_stock_clustering()

    card = {
        "version": "1.0.0",
        "datosSinteticos": True,
        "datosEntrenamientoSinteticos": True,
        "evaluadoEn": datetime.datetime.now(datetime.timezone.utc).isoformat(),
        "descripcion": "Model Card de evaluación offline para microservicio MS3 (No-Show y Clustering).",
        "modelos": {
            "no_show_prediction": no_show,
            "patient_clustering": patient_clustering,
            "stock_clustering": stock_clustering,
        },
    }

    with open(MODEL_CARD_PATH, "w", encoding="utf-8") as f:
        json.dump(card, f, indent=2, ensure_ascii=False)

    print(f"Model card written to: {MODEL_CARD_PATH}")
    return card


if __name__ == "__main__":
    card = generate_model_card()
    print("Done! Evaluation completed successfully.")
