import http.server
import logging
import os

# Настройка логирования
log_file_path = os.path.join(os.path.dirname(__file__), 'log.txt')
logging.basicConfig(filename=log_file_path, level=logging.INFO, format='%(asctime)s - %(message)s')

class MyHandler(http.server.BaseHTTPRequestHandler):
    def do_GET(self):
        # Логирование запроса
        ip = self.client_address[0]
        url = self.path
        logging.info(f'GET request from {ip} to {url}')

        # Отправка ответа клиенту
        self.send_response(200)
        self.send_header('Content-type', 'text/html')
        self.end_headers()
        self.wfile.write(b'Hello, world!')

    def log_message(self, format, *args):
        # Переопределение метода для отключения стандартного логирования
        return

def run(server_class=http.server.HTTPServer, handler_class=MyHandler):
    server_address = ('', 8000)  # Слушаем на всех интерфейсах на порту 8000
    httpd = server_class(server_address, handler_class)
    print('Starting server on port 8000...')
    httpd.serve_forever()

if __name__ == '__main__':
    run()
