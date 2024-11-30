import http.server
import socketserver
import logging
import os

# Настройка логирования
log_file_path = os.path.join(os.path.dirname(__file__), 'log.txt')
logging.basicConfig(filename=log_file_path, level=logging.INFO, format='%(asctime)s - %(message)s')

class MyHandler(http.server.SimpleHTTPRequestHandler):
    def do_GET(self):
        # Логирование запроса
        ip = self.client_address[0]
        url = self.path
        logging.info(f'GET request from {ip} to {url}')
        
        # Обработка статических файлов
        super().do_GET()

# Настройка порта и адреса
PORT = 8000  # Вы можете изменить порт, если это необходимо
with socketserver.TCPServer(("", PORT), MyHandler) as httpd:
    print(f"Serving on port {PORT}")
    httpd.serve_forever()
