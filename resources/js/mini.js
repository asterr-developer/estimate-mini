import { computed, createApp, ref } from 'vue';
import template from './mini.html?raw';
import './mini.css';

const telegram = window.Telegram?.WebApp;
telegram?.ready();
telegram?.expand();
const token = document.querySelector('meta[name="csrf-token"]').content;
const money = new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB' });
const price = value => Number(String(value).replace(/[ \u00a0\u202f]/g, '').replace(',', '.'));
const cents = value => Math.round(price(value) * 100);
const format = amount => money.format(amount / 100);

async function api(path = '', data) {
    const response = await fetch('/api/mini' + path, {
        method: data === undefined ? 'GET' : 'POST',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json',
            'X-CSRF-TOKEN': token, 'X-Telegram-Init-Data': telegram?.initData ?? '' },
        body: data === undefined ? undefined : JSON.stringify(data),
        cache: 'no-store',
    });
    const body = await response.json();
    if (!response.ok) throw Error(body.errors ? Object.values(body.errors)[0][0] : body.message || 'Не удалось загрузить данные.');
    return body;
}

createApp({
    template,
    setup() {
        const page = ref('catalog'), catalog = ref([]), requests = ref([]), cart = ref({});
        const selected = ref(null), category = ref(''), error = ref(''), pending = ref(false), notice = ref('');
        const orderNote = ref(''), question = ref(''), reply = ref('');
        const request = computed(() => requests.value.find(item => item.id === selected.value));
        const categories = computed(() => [...new Set(catalog.value.map(item => item.category))]);
        const visible = computed(() => catalog.value.filter(item => !category.value || item.category === category.value));
        const positions = computed(() => catalog.value.filter(item => cart.value[item.id]).map(item => ({
            ...item, quantity: cart.value[item.id], total: cents(item.price) * cart.value[item.id],
        })));
        const total = computed(() => positions.value.reduce((sum, line) => sum + line.total, 0));

        async function refresh() {
            const snapshot = await api();
            catalog.value = snapshot.catalog;
            requests.value = snapshot.requests;
        }
        async function act(operation) {
            if (pending.value) return;
            pending.value = true;
            error.value = '';
            notice.value = '';
            try { await operation(); }
            catch (failure) { error.value = failure.message; }
            finally { pending.value = false; }
        }
        function tab(next) {
            page.value = next;
            if (next === 'requests') act(refresh);
        }
        function change(item, quantity) {
            if (!cart.value[item.id] && Object.values(cart.value).filter(Boolean).length >= 30 && quantity > 0) {
                error.value = 'В заявку можно добавить до 30 разных услуг.';
                return;
            }
            error.value = '';
            cart.value[item.id] = Math.max(0, Math.min(999, Math.trunc(Number(quantity) || 0)));
        }
        function bundle() {
            const preferred = catalog.value.filter(item => /аудит|проектирован|дизайн|в[её]рстк/i.test(item.name)).slice(0, 3);
            for (const item of preferred.length ? preferred : catalog.value.slice(0, 3)) change(item, cart.value[item.id] || 1);
            tab('cart');
        }
        function ask(item) {
            question.value = 'Вопрос об услуге "' + item.name + '": ';
            tab('requests');
            selected.value = null;
        }
        function submitOrder() {
            return act(async () => {
                const result = await api('/requests', { items: positions.value.map(({ id, quantity }) => ({ id, quantity })), comment: orderNote.value });
                cart.value = {};
                orderNote.value = '';
                selected.value = result.id;
                page.value = 'requests';
                await refresh();
                notice.value = 'Заявка отправлена. Подрядчик ответит здесь.';
            });
        }
        function submitQuestion() {
            return act(async () => {
                const result = await api('/requests', { comment: question.value });
                question.value = '';
                selected.value = result.id;
                await refresh();
            });
        }
        function sendReply() {
            return act(async () => {
                await api('/requests/' + selected.value + '/messages', { comment: reply.value });
                reply.value = '';
                await refresh();
            });
        }

        act(refresh);
        return { page, tab, visible, categories, category, cart, positions, total, requests, request, selected, error, pending,
            notice, orderNote, question, reply, format, cents, change, bundle, ask, submitOrder, submitQuestion, sendReply };
    },
}).mount('#mini-app');
