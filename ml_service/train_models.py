"""
Machine Learning Model Training Pipeline for Dental Clinic MS3.
Implements:
1. Supervised Learning: Random Forest + Gradient Boosting for No-Show (Inasistencia) Prediction.
   Features: cancelaciones_previas, dias_anticipacion, dia_semana, hora_cita,
             banda_horaria (0=mañana, 1=tarde), duracion_minutos, tipo_tratamiento_riesgo, edad.
2. Unsupervised Learning: K-Means Clustering for Patient Behavioral Segmentation.
   Cluster names (spec-exact): Frecuente y Puntual, Riesgo de Abandono / Inconstante,
   Atención Esporádica / Urgencias.
3. Unsupervised Learning: K-Means Clustering for Stock / Insumo ABC Rotation Analysis.
   Cluster names (spec-exact): Alta Rotación Crítica, Consumo Estándar, Baja Rotación.

NOTE: All models are trained on DATOS SINTÉTICOS (synthetic data). There is no real
patient history in this environment. The models are for demonstration and portfolio use
under a non-commercial license.
"""

import os
import joblib
import numpy as np
import pandas as pd
from sklearn.pipeline import Pipeline
from sklearn.preprocessing import StandardScaler
from sklearn.ensemble import RandomForestClassifier, GradientBoostingClassifier
from sklearn.cluster import KMeans
from sklearn.model_selection import train_test_split
from sklearn.metrics import accuracy_score

MODELS_DIR = os.path.join(os.path.dirname(__file__), "models")
os.makedirs(MODELS_DIR, exist_ok=True)

# Spec-exact patient cluster names (S11), ordered high→medium→low engagement
PATIENT_CLUSTER_NAMES = [
    "Frecuente y Puntual",
    "Riesgo de Abandono / Inconstante",
    "Atención Esporádica / Urgencias",
]

# Spec-exact stock cluster names (S11), ordered high→medium→low rotation
STOCK_CLUSTER_NAMES = [
    "Alta Rotación Crítica",
    "Consumo Estándar",
    "Baja Rotación",
]


def train_no_show_model():
    print("[1/3] Training Supervised No-Show Classification Model...")
    np.random.seed(42)
    n_samples = 2000

    edad = np.random.randint(18, 75, n_samples)
    dias_anticipacion = np.random.randint(0, 45, n_samples)
    cancelaciones_previas = np.random.poisson(lam=0.8, size=n_samples)
    dia_semana = np.random.randint(1, 7, n_samples)  # 1=Mon, 6=Sat
    hora_cita = np.random.randint(8, 19, n_samples)   # 8:00–18:00
    # banda_horaria: 0=mañana (8–12), 1=tarde (13+)
    banda_horaria = (hora_cita >= 13).astype(int)
    # duracion_minutos: typical appointment durations
    duracion_minutos = np.random.choice([20, 30, 45, 60, 90], p=[0.1, 0.4, 0.3, 0.15, 0.05], size=n_samples)
    tipo_tratamiento_riesgo = np.random.choice([0, 1, 2], p=[0.5, 0.35, 0.15], size=n_samples)

    # Probability of No-Show: synthetic domain logic
    # Higher cancelaciones, long lead, Monday/Friday, afternoon band, long/invasive treatment increase risk
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

    X_train, X_test, y_train, y_test = train_test_split(X, y, test_size=0.2, random_state=42, stratify=y)

    pipeline = Pipeline([
        ("scaler", StandardScaler()),
        ("classifier", RandomForestClassifier(
            n_estimators=150,
            max_depth=7,
            min_samples_split=4,
            random_state=42,
            class_weight="balanced",
        ))
    ])

    pipeline.fit(X_train, y_train)
    acc = accuracy_score(y_test, pipeline.predict(X_test))
    print(f"  -> No-Show Model Accuracy (RF): {acc * 100:.2f}%")

    output_path = os.path.join(MODELS_DIR, "no_show_pipeline.joblib")
    joblib.dump(pipeline, output_path)
    print(f"  -> Model saved to: {output_path}")
    print("  -> Features (in order): cancelaciones_previas, dias_anticipacion, dia_semana, hora_cita, "
          "banda_horaria, duracion_minutos, tipo_tratamiento_riesgo, edad")
    print("  -> NOTA: Entrenado con datos sintéticos (synthetic data).")


def _sorted_cluster_centers(pipeline, sort_col_idx: int, reverse: bool = True) -> np.ndarray:
    """Return cluster center indices sorted semantically by a column value."""
    scaler = pipeline.named_steps["scaler"]
    kmeans = pipeline.named_steps["kmeans"]
    unscaled = scaler.inverse_transform(kmeans.cluster_centers_)
    scores = unscaled[:, sort_col_idx]
    if reverse:
        return np.argsort(-scores)
    return np.argsort(scores)


def train_patient_clustering_model():
    print("[2/3] Training Unsupervised Patient Segmentation (K-Means)...")
    print(f"  -> Cluster names (spec-exact): {PATIENT_CLUSTER_NAMES}")
    np.random.seed(42)
    n_patients = 800

    # Synthetic patient behavioral profiles
    profile = np.random.choice([0, 1, 2], p=[0.25, 0.50, 0.25], size=n_patients)

    total_citas = np.zeros(n_patients)
    tasa_asistencia = np.zeros(n_patients)
    dias_promedio_entre_citas = np.zeros(n_patients)
    gasto_total_estimado = np.zeros(n_patients)

    for i in range(n_patients):
        if profile[i] == 0:  # Frecuente y Puntual
            total_citas[i] = np.random.randint(8, 25)
            tasa_asistencia[i] = np.random.uniform(0.85, 1.0)
            dias_promedio_entre_citas[i] = np.random.randint(20, 60)
            gasto_total_estimado[i] = np.random.uniform(250.0, 1200.0)
        elif profile[i] == 1:  # Riesgo de Abandono / Inconstante
            total_citas[i] = np.random.randint(2, 8)
            tasa_asistencia[i] = np.random.uniform(0.40, 0.75)
            dias_promedio_entre_citas[i] = np.random.randint(90, 240)
            gasto_total_estimado[i] = np.random.uniform(80.0, 300.0)
        else:  # Atención Esporádica / Urgencias
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

    pipeline = Pipeline([
        ("scaler", StandardScaler()),
        ("kmeans", KMeans(n_clusters=3, random_state=42, n_init=10))
    ])
    pipeline.fit(df_patients)

    # Sort cluster centers: Frecuente (high total_citas * gasto) first
    sorted_indices = _sorted_cluster_centers(pipeline, sort_col_idx=0)
    pipeline.named_steps["kmeans"].cluster_centers_ = \
        pipeline.named_steps["kmeans"].cluster_centers_[sorted_indices]

    output_path = os.path.join(MODELS_DIR, "patient_clustering_pipeline.joblib")
    joblib.dump(pipeline, output_path)
    print(f"  -> Patient Clustering Pipeline saved to: {output_path}")
    print("  -> NOTA: Entrenado con datos sintéticos (synthetic data).")


def train_stock_clustering_model():
    print("[3/3] Training Unsupervised Inventory ABC / Rotation Clustering...")
    print(f"  -> Cluster names (spec-exact): {STOCK_CLUSTER_NAMES}")
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

    pipeline = Pipeline([
        ("scaler", StandardScaler()),
        ("kmeans", KMeans(n_clusters=3, random_state=42, n_init=10))
    ])
    pipeline.fit(df_stock)

    # Sort: Alta Rotación (high consumo * costo) first
    sorted_indices = _sorted_cluster_centers(pipeline, sort_col_idx=0)
    pipeline.named_steps["kmeans"].cluster_centers_ = \
        pipeline.named_steps["kmeans"].cluster_centers_[sorted_indices]

    output_path = os.path.join(MODELS_DIR, "stock_clustering_pipeline.joblib")
    joblib.dump(pipeline, output_path)
    print(f"  -> Stock Clustering Pipeline saved to: {output_path}")
    print("  -> NOTA: Entrenado con datos sintéticos (synthetic data).")


if __name__ == "__main__":
    train_no_show_model()
    train_patient_clustering_model()
    train_stock_clustering_model()
    print("\nAll ML Pipelines trained and exported successfully!")
    print("NOTE: All models trained on DATOS SINTÉTICOS (synthetic data).")
