import requests
import time
import json
import os
from datetime import datetime

# --- CONFIGURAÇÕES ---
# URL da sua API PHP. Altere para o endereço do seu servidor.
API_URL = os.getenv("API_URL", "http://localhost/api.php") 
# A mesma chave secreta definida no api.php. É mais seguro usar variáveis de ambiente.
API_SECRET_KEY = os.getenv("API_SECRET_KEY", "B3k7sPz9@wXv$rTqL!n2mCgFhJd5uA8i")
# URL da API do Bot-Bot
BOTBOT_API_URL = "https://botbot.chat/api/create-message"
# Intervalo entre as verificações (em segundos). Um valor baixo simula tempo real.
CHECK_INTERVAL = 10  # 10 segundos

# Cria uma sessão de requests para gerenciar os cabeçalhos
session = requests.Session()
session.headers.update({
    "Authorization": f"Bearer {API_SECRET_KEY}",
    "User-Agent": "AcademiaBotWorker/1.0"
})

def get_pending_notifications():
    """Busca notificações pendentes na sua API PHP."""
    try:
        # Usa a sessão que já tem o cabeçalho de autorização configurado
        response = session.get(f"{API_URL}?action=get_notifications", timeout=15)
        response.raise_for_status()  # Lança um erro para códigos HTTP 4xx/5xx
        data = response.json()
        return data.get("notifications", [])
    except requests.exceptions.RequestException as e:
        print(f"Erro ao buscar notificações: {e}")
        return []

def send_message_via_botbot(credentials, to, message):
    """Envia uma mensagem usando a API do Bot-Bot."""
    if not to or not isinstance(to, str):
        print(f"Número de telefone inválido ou ausente: {to}. Pulando.")
        return False
        
    cleaned_phone = ''.join(filter(str.isdigit, to))
    
    # Garante que o número tenha o código de país 55
    if not cleaned_phone.startswith('55'):
        cleaned_phone = f'55{cleaned_phone}'

    payload = {
        "appkey": credentials["appkey"],
        "authkey": credentials["authkey"],
        "to": cleaned_phone,
        "message": message
    }
    try:
        response = requests.post(BOTBOT_API_URL, data=payload, timeout=20)
        response.raise_for_status()
        response_data = response.json()
        print(f"Mensagem enviada para {cleaned_phone}. Resposta: {response_data}")
        return response_data.get("message_status") == "Success"
    except requests.exceptions.RequestException as e:
        print(f"Erro ao enviar mensagem para {cleaned_phone}: {e}")
        if e.response is not None:
            print(f"Detalhes do erro: {e.response.text}")
        return False

def mark_notification_as_sent(aluno_id, notification_type):
    """Informa à sua API PHP que a notificação foi enviada com sucesso."""
    payload = {
        "aluno_id": aluno_id,
        "type": notification_type
    }
    try:
        # Adiciona o Content-Type apenas para esta requisição POST
        post_headers = session.headers.copy()
        post_headers["Content-Type"] = "application/json"
        
        response = session.post(f"{API_URL}?action=mark_as_sent", headers=post_headers, data=json.dumps(payload), timeout=10)
        response.raise_for_status()
        print(f"Notificação para aluno {aluno_id} (tipo: {notification_type}) marcada como enviada.")
    except requests.exceptions.RequestException as e:
        print(f"Erro ao marcar notificação como enviada para o aluno {aluno_id}: {e}")

def main_loop():
    """Loop principal do bot."""
    print(">>> Bot de Notificações iniciado.")
    print(f">>> Verificando a cada {CHECK_INTERVAL} segundos...")
    while True:
        print(f"\n--- {time.ctime()} ---")
        print("Verificando notificações pendentes...")
        
        notifications = get_pending_notifications()
        
        if not notifications:
            print("Nenhuma notificação pendente encontrada.")
        else:
            print(f"Encontradas {len(notifications)} notificações para processar.")
            for notification in notifications:
                send_at_str = notification.get('send_at')
                
                # Verifica se a notificação está pronta para ser enviada
                should_send = False
                if not send_at_str:
                    print(f"Notificação (tipo: {notification.get('type')}) para {notification['to']} tem envio imediato.")
                    should_send = True
                else:
                    send_at_time = datetime.fromisoformat(send_at_str)
                    if datetime.now() >= send_at_time:
                        print(f"Notificação (tipo: {notification.get('type')}) para {notification['to']} agendada para {send_at_str} está pronta para envio.")
                        should_send = True
                    else:
                        print(f"Ainda não é hora de enviar a notificação para {notification['to']}. Agendado para: {send_at_str}")

                if should_send:
                    print(f"Enviando notificação para o telefone: {notification['to']}")
                    success = send_message_via_botbot(notification['credentials'], notification['to'], notification['message'])
                    if success:
                        # Se o envio foi bem-sucedido, marca no banco de dados via API
                        mark_notification_as_sent(notification['aluno_id'], notification.get('type', 'unknown'))
                
                time.sleep(3) # Pequena pausa para não sobrecarregar a API do Bot-Bot

        print(f"Aguardando {CHECK_INTERVAL} segundos para a próxima verificação...")
        time.sleep(CHECK_INTERVAL)

if __name__ == "__main__":
    # Inicia o loop principal do bot.
    main_loop()
