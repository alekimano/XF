from flask import Flask, request
import logging

app = Flask(__name__)

# Настройка логирования
logging.basicConfig(filename='post_requests.log', level=logging.INFO)

@app.route('/home/bitrix/www/bitrix/admin/', methods=['POST'])
def log_post_request():
    # Логируем информацию о запросе
    logging.info('New POST request received')
    
    # Логируем данные запроса
    logging.info(f'Headers: {request.headers}')
    logging.info(f'Body: {request.form}')

    # Возвращаем успешный ответ
    return 'Request logged', 200

if __name__ == '__main__':
    app.run(port=5000)
