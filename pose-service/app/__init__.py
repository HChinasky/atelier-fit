"""Pose-detection microservice (FastAPI, CPU-only).

A thin, stateless wrapper around MediaPipe Pose Landmarker. It is a *pure
detector*: it reports facts about an image (how many people, where they are,
their body landmarks) and never decides whether a photo is "good enough" — that
policy lives in the calling backend.
"""
