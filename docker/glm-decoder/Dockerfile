FROM python:3.12-slim

WORKDIR /app

COPY pipeline/glm-decoder/requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY pipeline/glm-decoder/glm_decoder.py .

ENTRYPOINT ["python", "glm_decoder.py"]
