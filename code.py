from flask import Flask, request
import logging

app = Flask(__name__)

# Настройка логирования
logging.basicConfig(filename='log.txt', level=logging.INFO, format='%(asctime)s - %(message)s')

@app.route('/', methods=['GET'])
def index():
    log_request()
    return "Hello, World!"

def log_request():
    ip = request.remote_addr
    url = request.url
    logging.info(f'GET request from {ip} to {url}')

if __name__ == '__main__':
    app.run(host='0.0.0.0', port=5000)
