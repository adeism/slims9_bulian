/**
 * Chatbot plugin front-end
 *
 * Plain DOM, no dependencies, no inline handlers: it must survive a strict
 * CSP and run on a stock SLiMS OPAC (which has no jQuery in the default
 * theme). Database text is always written with textContent, never
 * innerHTML, so a title containing markup stays text.
 *
 * Protocol: POST JSON {message} or {confirm} to `data-endpoint` with the
 * session CSRF token; the server answers with
 *   {ok, reply:{text, blocks:[...]}, suggestions:[...], pending:{token}}
 */
(function () {
    'use strict';

    var SAFE_HREF = /^(https?:\/\/|\/|\.\/)/i;

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (typeof text === 'string') {
            node.textContent = text;
        }
        return node;
    }

    function parseJson(value, fallback) {
        try {
            var parsed = JSON.parse(value);
            return parsed === null ? fallback : parsed;
        } catch (error) {
            return fallback;
        }
    }

    function init(root) {
        var endpoint = root.getAttribute('data-endpoint') || '';
        var csrf = root.getAttribute('data-csrf') || '';
        var strings = parseJson(root.getAttribute('data-strings'), {});
        var chips = parseJson(root.getAttribute('data-chips'), []);
        var log = root.querySelector('.chatbot__log');
        var chipBar = root.querySelector('.chatbot__chips');
        var form = root.querySelector('.chatbot__form');
        var input = root.querySelector('.chatbot__input');
        var send = root.querySelector('.chatbot__send');
        var busy = false;

        function scrollDown() {
            log.scrollTop = log.scrollHeight;
        }

        function message(kind, text) {
            var item = el('li', 'chatbot__msg chatbot__msg--' + kind);
            var body = el('div', 'chatbot__msg-body');

            if (text) {
                body.appendChild(el('p', '', text));
            }

            item.appendChild(body);
            log.appendChild(item);
            scrollDown();

            return body;
        }

        function typing() {
            var item = el('li', 'chatbot__msg chatbot__msg--bot chatbot__typing');
            item.setAttribute('aria-hidden', 'true');
            item.appendChild(el('p', '', strings.thinking || '…'));
            log.appendChild(item);
            scrollDown();

            return item;
        }

        function setBusy(state) {
            busy = state;
            log.setAttribute('aria-busy', state ? 'true' : 'false');
            if (send) {
                send.disabled = state;
            }
            if (input) {
                input.disabled = state;
            }
        }

        function notice(text, tone) {
            return el('div', 'chatbot__notice chatbot__notice--' + (tone || 'info'), text);
        }

        function renderBlocks(container, blocks) {
            (blocks || []).forEach(function (block) {
                if (!block || !block.type) {
                    return;
                }

                if (block.type === 'text' && block.text) {
                    container.appendChild(el('p', '', String(block.text)));
                    return;
                }

                if (block.type === 'notice' && block.text) {
                    container.appendChild(notice(String(block.text), block.tone));
                    return;
                }

                if (block.type === 'facts' && Array.isArray(block.items)) {
                    var dl = el('dl', 'chatbot__facts');
                    block.items.forEach(function (row) {
                        dl.appendChild(el('dt', '', String(row.label || '')));
                        dl.appendChild(el('dd', '', String(row.value === undefined ? '-' : row.value)));
                    });
                    container.appendChild(dl);
                    return;
                }

                if (block.type === 'link' && block.href) {
                    var href = String(block.href);
                    if (SAFE_HREF.test(href)) {
                        var link = el('a', 'chatbot__link', String(block.label || href));
                        link.setAttribute('href', href);
                        container.appendChild(link);
                    }
                    return;
                }

                if (block.type === 'list' && Array.isArray(block.items)) {
                    var list = el('ul', 'chatbot__list');

                    block.items.forEach(function (row) {
                        var item = el('li', 'chatbot__item');
                        var title = String(row.title || '-');
                        var href = row.href ? String(row.href) : '';

                        if (href && SAFE_HREF.test(href)) {
                            var anchor = el('a', 'chatbot__item-title', title);
                            anchor.setAttribute('href', href);
                            anchor.setAttribute('rel', 'noopener');
                            item.appendChild(anchor);
                        } else {
                            item.appendChild(el('strong', 'chatbot__item-title', title));
                        }

                        if (row.meta) {
                            item.appendChild(el('span', 'chatbot__item-meta', String(row.meta)));
                        }

                        if (row.badge) {
                            var tone = row.tone === 'warn' ? ' warn' : (row.tone === 'ok' ? ' ok' : '');
                            item.appendChild(el('span', 'chatbot__badge chatbot__badge--' + (tone.trim() || 'muted'), String(row.badge)));
                        }

                        list.appendChild(item);
                    });

                    container.appendChild(list);
                }
            });
        }

        function renderChips(list) {
            chipBar.textContent = '';

            (list || []).forEach(function (text) {
                if (!text) {
                    return;
                }

                var chip = el('button', 'chatbot__chip', String(text));
                chip.setAttribute('type', 'button');
                chip.addEventListener('click', function () {
                    if (busy) {
                        return;
                    }
                    sendPayload({ message: String(text) }, String(text));
                });
                chipBar.appendChild(chip);
            });
        }

        function renderPending(container, pending) {
            if (!pending || !pending.token) {
                return;
            }

            var row = el('div', 'chatbot__actions');

            var confirm = el('button', 'chatbot__btn chatbot__btn--primary', pending.label || strings.confirm || 'OK');
            confirm.setAttribute('type', 'button');

            var cancel = el('button', 'chatbot__btn', strings.cancel || 'Cancel');
            cancel.setAttribute('type', 'button');

            confirm.addEventListener('click', function () {
                if (busy) {
                    return;
                }
                row.textContent = '';
                sendPayload({ confirm: String(pending.token) }, null);
            });

            cancel.addEventListener('click', function () {
                row.textContent = '';
                appendBot(strings.cancelled || 'Cancelled.');
            });

            row.appendChild(confirm);
            row.appendChild(cancel);
            container.appendChild(row);
        }

        function appendBot(text, blocks, pending, tone) {
            var body = message('bot', text);

            if (tone === 'warn' || tone === 'error') {
                body.parentNode.classList.add('chatbot__msg--' + (tone === 'error' ? 'error' : 'warn'));
            }

            renderBlocks(body, blocks);
            renderPending(body, pending);
            scrollDown();
        }

        function readResponse(response) {
            return response.text().then(function (text) {
                var data = parseJson(text, null);

                if (data === null) {
                    throw new Error('bad-json');
                }

                return { status: response.status, data: data };
            });
        }

        function sendPayload(payload, label) {
            if (busy) {
                return;
            }

            if (label) {
                message('user', label);
            }

            setBusy(true);
            var bubble = typing();

            var body = Object.assign({ csrf_token: csrf }, payload || {});

            fetch(endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrf,
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify(body)
            }).then(readResponse).then(function (result) {
                bubble.remove();

                var data = result.data || {};

                if (!result.status || result.status >= 400 || data.ok === false) {
                    var reason = (data.reply && data.reply.text) || (data.error && data.error.message) || strings.network;
                    var kind = result.status === 403 ? strings.expired : reason;

                    message('error', kind);

                    if (data.reply && data.reply.blocks) {
                        renderBlocks(message('bot'), data.reply.blocks);
                    }

                    return;
                }

                appendBot(
                    (data.reply && data.reply.text) || '',
                    (data.reply && data.reply.blocks) || [],
                    data.pending,
                    data.meta && data.meta.tone
                );

                if (Array.isArray(data.suggestions) && data.suggestions.length) {
                    renderChips(data.suggestions);
                }
            }).catch(function () {
                bubble.remove();
                message('error', strings.network || 'Network error');
            }).then(function () {
                setBusy(false);
                if (input) {
                    input.focus();
                }
            });
        }

        if (!endpoint || !csrf) {
            appendBot(strings.expired || 'Session expired. Reload the page.');
            setBusy(true);
            return;
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();

            var value = input ? input.value.trim() : '';

            if (value === '' || busy) {
                return;
            }

            if (input) {
                input.value = '';
            }

            sendPayload({ message: value }, value);
        });

        if (input) {
            input.setAttribute('placeholder', strings.placeholder || '');
        }

        appendBot(strings.greeting || '');
        renderChips(chips);
        setBusy(false);

        if (input) {
            input.focus();
        }
    }

    function boot() {
        var nodes = document.querySelectorAll('[data-chatbot]');

        Array.prototype.forEach.call(nodes, function (node) {
            if (node.getAttribute('data-chatbot-ready') === '1') {
                return;
            }

            node.setAttribute('data-chatbot-ready', '1');
            init(node);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
