// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

'use strict';

(function (scope) {
    const MAX_PENDING_EVENT_LENGTH = 1024 * 1024;
    const EVENT_BOUNDARY = /\r\n\r\n|\r\n\n|\n\r\n|\n\n|\r\r/;

    class SseParser {
        constructor(onEvent) {
            this.onEvent = onEvent;
            this.buffer = '';
        }

        feed(chunk) {
            this.buffer += chunk;
            let boundary;
            while ((boundary = EVENT_BOUNDARY.exec(this.buffer)) !== null) {
                const block = this.buffer.slice(0, boundary.index);
                this.buffer = this.buffer.slice(boundary.index + boundary[0].length);
                this.parse(block);
            }
            if (this.buffer.length > MAX_PENDING_EVENT_LENGTH) {
                throw new Error('O servidor enviou um evento de progresso excessivamente grande.');
            }
        }

        parse(block) {
            let event = 'message';
            const data = [];
            for (const line of block.replace(/^\uFEFF/, '').split(/\r\n|\r|\n/)) {
                if (line.startsWith('event:')) {
                    event = line.slice(6).trim();
                } else if (line.startsWith('data:')) {
                    data.push(line.slice(5).replace(/^ /, ''));
                }
            }
            if (data.length === 0) {
                return;
            }
            const raw = data.join('\n');
            let message;
            try {
                message = JSON.parse(raw);
            } catch {
                message = raw;
            }
            this.onEvent(event, message);
        }
    }

    function addMessage(target, severity, content, doc = target.ownerDocument || scope.document) {
        const item = doc.createElement('div');
        item.className = 'alert' + (['warning', 'success', 'info'].includes(severity) ? ' ' + severity : '');
        item.textContent = String(content);
        target.appendChild(item);
        item.scrollIntoView?.({ block: 'nearest' });
        return item;
    }

    async function submit(form, messages, submitButton, options = {}) {
        if (submitButton.disabled) {
            return false;
        }

        const win = options.window || scope;
        const doc = options.document || form.ownerDocument || win.document;
        const createFormData = options.createFormData || (element => new win.FormData(element));
        const data = createFormData(form);
        if (data.get('atualiza_producao') === '1' && !win.confirm(
            'Esta operação grava documentos financeiros no Akaunting. Conferiu a prévia e a competência?'
        )) {
            return false;
        }

        const heading = doc.createElement('h3');
        heading.textContent = 'Execução iniciada em ' + (options.now || (() => new Date()))().toLocaleString('pt-BR');
        messages.appendChild(heading);
        submitButton.disabled = true;

        let completed = false;
        let succeeded = false;
        try {
            const fetchRequest = options.fetch || win.fetch.bind(win);
            const response = await fetchRequest(form.action, {
                method: 'POST',
                credentials: 'same-origin',
                body: data,
                headers: { Accept: 'text/event-stream' },
                cache: 'no-store',
            });
            if (!response.ok) {
                throw new Error(response.status === 403
                    ? 'Acesso negado. Recarregue a página; para operações de gravação, informe a senha configurada no servidor.'
                    : 'O servidor recusou a execução (HTTP ' + response.status + '). Verifique o formulário e os logs.');
            }
            if (!response.body || typeof response.body.getReader !== 'function') {
                throw new Error('O navegador ou proxy não disponibilizou o fluxo de progresso.');
            }

            const parser = new SseParser((eventName, message) => {
                if (completed) {
                    return;
                }
                if (eventName === 'done' || eventName === 'failed') {
                    completed = true;
                    succeeded = eventName === 'done';
                    addMessage(messages, succeeded ? 'success' : '', message, doc);
                } else if (eventName === 'warning' || eventName === 'alert') {
                    addMessage(messages, 'warning', message, doc);
                } else if (eventName === 'info') {
                    addMessage(messages, 'info', message, doc);
                }
            });
            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            try {
                while (true) {
                    const packet = await reader.read();
                    if (packet.done) {
                        break;
                    }
                    parser.feed(decoder.decode(packet.value, { stream: true }));
                }
                parser.feed(decoder.decode());
            } finally {
                reader.releaseLock?.();
            }
            if (!completed) {
                throw new Error('A conexão encerrou sem resultado final. Consulte o log pelo horário antes de reexecutar, pois podem existir documentos gravados.');
            }
            return succeeded;
        } catch (error) {
            addMessage(messages, '', error.message || 'Falha de conexão com o servidor.', doc);
            return false;
        } finally {
            submitButton.disabled = false;
        }
    }

    function bind(doc = scope.document, options = {}) {
        const form = doc?.getElementById('form-gera-producao');
        const messages = doc?.getElementById('messages');
        const submitButton = doc?.getElementById('submit');
        if (!form || !messages || !submitButton) {
            return false;
        }

        form.addEventListener('submit', event => {
            event.preventDefault();
            void submit(form, messages, submitButton, options);
        });
        return true;
    }

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = { SseParser, addMessage, submit, bind };
    } else {
        scope.addEventListener('DOMContentLoaded', () => bind());
    }
})(typeof window === 'undefined' ? {} : window);
