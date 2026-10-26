'use strict';

(function (scope) {
    class SseParser {
        constructor(onEvent) {
            this.onEvent = onEvent;
            this.buffer = '';
        }

        feed(chunk) {
            this.buffer += chunk;
            let found;
            while ((found = /\r?\n\r?\n/.exec(this.buffer)) !== null) {
                const block = this.buffer.slice(0, found.index);
                this.buffer = this.buffer.slice(found.index + found[0].length);
                this.parse(block);
            }
        }

        parse(block) {
            let event = 'message';
            const data = [];
            for (const line of block.split(/\r?\n/)) {
                if (line.startsWith('event:')) {
                    event = line.slice(6).trim();
                } else if (line.startsWith('data:')) {
                    data.push(line.slice(5).replace(/^ /, ''));
                }
            }
            if (data.length) {
                let message;
                try {
                    message = JSON.parse(data.join('\n'));
                } catch {
                    message = data.join('\n');
                }
                this.onEvent(event, message);
            }
        }
    }

    function addMessage(target, severity, content) {
        const item = document.createElement('div');
        item.className = 'alert ' + (['warning', 'success', 'info'].includes(severity) ? severity : '');
        item.textContent = String(content);
        target.appendChild(item);
        item.scrollIntoView({ block: 'nearest' });
    }

    async function submit(form, messages, submitButton) {
        if (submitButton.disabled) {
            return;
        }
        const data = new FormData(form);
        if (data.get('atualiza_producao') === '1' && !window.confirm(
            'Esta operação grava documentos financeiros no Akaunting. Conferiu a prévia e a competência?'
        )) {
            return;
        }
        const heading = document.createElement('h3');
        heading.textContent = 'Execução iniciada em ' + new Date().toLocaleString('pt-BR');
        messages.appendChild(heading);
        submitButton.disabled = true;

        let completed = false;
        try {
            const response = await fetch(form.action, {
                method: 'POST',
                credentials: 'same-origin',
                body: data,
                headers: { 'Accept': 'text/event-stream' },
                cache: 'no-store',
            });
            if (!response.ok) {
                throw new Error(response.status === 403
                    ? 'Acesso negado. Recarregue a página; para operações de gravação, informe a senha configurada no servidor.'
                    : 'O servidor recusou a execução (HTTP ' + response.status + '). Verifique o formulário e os logs.');
            }
            if (!response.body) {
                throw new Error('O navegador ou proxy não disponibilizou o fluxo de progresso.');
            }

            const parser = new SseParser((eventName, message) => {
                if (completed) {
                    return;
                }
                if (eventName === 'done' || eventName === 'failed') {
                    completed = true;
                    addMessage(messages, eventName === 'done' ? 'success' : '', message);
                } else if (eventName === 'warning' || eventName === 'alert') {
                    addMessage(messages, 'warning', message);
                } else if (eventName === 'info') {
                    addMessage(messages, 'info', message);
                }
            });
            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            while (true) {
                const packet = await reader.read();
                if (packet.done) {
                    break;
                }
                parser.feed(decoder.decode(packet.value, { stream: true }));
            }
            parser.feed(decoder.decode());
            if (!completed) {
                throw new Error('A conexão encerrou sem resultado final. Consulte o log pelo horário antes de reexecutar, pois podem existir documentos gravados.');
            }
        } catch (error) {
            addMessage(messages, '', error.message || 'Falha de conexão com o servidor.');
        } finally {
            submitButton.disabled = false;
        }
    }

    function bind() {
        const form = document.getElementById('form-gera-producao');
        const messages = document.getElementById('messages');
        const submitButton = document.getElementById('submit');
        if (form && messages && submitButton) {
            form.addEventListener('submit', event => {
                event.preventDefault();
                void submit(form, messages, submitButton);
            });
        }
    }

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = { SseParser };
    } else {
        scope.addEventListener('DOMContentLoaded', bind);
    }
})(typeof window === 'undefined' ? {} : window);
