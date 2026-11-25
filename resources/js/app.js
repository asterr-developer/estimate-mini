import { computed, createApp, nextTick, ref, watch } from 'vue';
import { createRouter, createWebHistory, useRoute, useRouter } from 'vue-router';
import template from './estimate.html?raw';
import documentTemplate from './proposal-document.html?raw';
import './estimate.css';

const screen = { render: () => null };
const router = createRouter({
    history: createWebHistory(),
    routes: ['/', '/history', '/workspace', '/today', '/telegram-inbox', '/p/:token', '/reset-password/:token'].map(path => ({ path, component: screen })),
});
let csrf = document.querySelector('meta[name="csrf-token"]').content;
const rubles = new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB', maximumFractionDigits: 2, minimumFractionDigits: 2 });
const formatMoney = cents => rubles.format(cents / 100);
function toCents(value, maximum = 1_000_000_000) {
    const parts = String(value ?? '').trim().replace(/[ \u00a0\u202f]/g, '').match(/^([0-9]{1,12})(?:[,.]([0-9]{1,2}))?$/);
    if (!parts) return null;
    const cents = Number(parts[1]) * 100 + Number((parts[2] ?? '').padEnd(2, '0'));
    return cents <= maximum ? cents : null;
}
const unitPrice = row => row.price_cents ?? toCents(row.price) ?? 0;
const ratio = (amount, numerator, denominator = 100) => Math.floor((amount * numerator + Math.floor(denominator / 2)) / denominator);

async function api(path, data, signal, method = data === undefined ? 'GET' : 'POST') {
    const response = await fetch('/api' + path, {
        method,
        headers: { Accept: 'application/json', ...(data instanceof FormData ? {} : { 'Content-Type': 'application/json' }), 'X-CSRF-TOKEN': csrf },
        body: data === undefined || data instanceof FormData ? data : JSON.stringify(data),
        signal,
        cache: 'no-store',
    });
    if (response.status === 204) return;
    const result = await response.json();
    if (!response.ok) throw Error(result.errors ? Object.values(result.errors)[0][0] : result.message);
    return result;
}

const ProposalDocument = {
    props: ['data', 'number'],
    methods: { formatMoney, unitPrice },
    computed: {
        groups() {
            const groups = (this.data.stages ?? []).map(stage => ({
                ...stage, lines: this.data.lines.filter(line => line.stage === stage.key),
            }));
            const unassigned = this.data.lines.filter(line => !groups.some(stage => stage.key === line.stage));
            if (unassigned.length) groups.push({ key: 'unassigned', name: groups.length ? 'Работы вне этапов' : '', lines: unassigned });
            return groups;
        },
    },
    template: documentTemplate,
};

createApp({
    components: { ProposalDocument },
    template,
    setup() {
        const route = useRoute();
        const navigation = useRouter();
        const page = computed(() => route.path);
        const catalog = ref([]);
        const user = ref(null);
        const sessionReady = ref(false);
        const emptyRecords = () => ({ clients: [], services: [], drafts: [], templates: [], contractors: [] });
        const records = ref(emptyRecords());
        const telegramInbox = ref([]);
        const telegramReplies = ref({});

        async function loadTelegramInbox(signal) {
            telegramInbox.value = await api('/telegram/inbox', undefined, signal);
        }

        function replyTelegram(item) {
            return send(async () => {
                const form = telegramReplies.value[item.id] ?? {};
                await api('/telegram/inbox/' + item.id + '/reply', form);
                telegramReplies.value[item.id] = { comment: '', proposal_id: '' };
                await loadTelegramInbox();
                notice.value = 'Ответ сохранён в обращении.';
            });
        }
        const draftId = ref(null);
        const draftVersion = ref(null);
        const accountForm = ref({ name: '', email: '', password: '' });
        const register = ref(false);
        const forgot = ref(false);
        const resetForm = ref({ email: String(route.query.email ?? ''), password: '', password_confirmation: '' });
        const templateName = ref('');
        const templateDialog = ref(null);
        const activeClient = ref(null);
        const clientCard = computed(() => records.value.clients.find(row => row.id === activeClient.value));
        const clientProposals = computed(() => history.value.filter(row => row.client_record_id === activeClient.value));
        const clientDrafts = computed(() => records.value.drafts.filter(row => row.data.client_record_id === activeClient.value));
        const changeComment = ref('');
        const lineComments = ref({});
        const archiveView = ref(false);
        const receiptsDialog = ref(null);
        const receiptItem = ref(null);
        const receiptData = ref(null);
        const receiptInput = ref({});
        const moscowDay = new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Moscow', year: 'numeric', month: '2-digit', day: '2-digit' });
        const followUps = computed(() => {
            const today = moscowDay.format(new Date());
            const horizon = new Date(today + 'T00:00:00Z');
            horizon.setUTCDate(horizon.getUTCDate() + 3);
            const stale = new Date(today + 'T00:00:00Z');
            stale.setUTCDate(stale.getUTCDate() - 7);
            return {
                changes: history.value.filter(item => !item.archived_at && item.status === 'changes'
                    && !history.value.some(version => version.revision_of === item.id && !['withdrawn', 'expired'].includes(version.status))),
                expiring: history.value.filter(item => !item.archived_at && ['pending', 'changes'].includes(item.status) && item.snapshot.valid_until
                    && item.snapshot.valid_until >= today && item.snapshot.valid_until <= horizon.toISOString().slice(0, 10))
                    .sort((a, b) => a.snapshot.valid_until.localeCompare(b.snapshot.valid_until)),
                stale: records.value.drafts.filter(item => item.updated_at.slice(0, 10) <= stale.toISOString().slice(0, 10)),
                overdue: history.value.filter(item => !item.archived_at && item.status !== 'withdrawn'
                    && item.payment_summary?.schedule.some(payment => payment.overdue)),
            };
        });
        const accountDialog = ref(null);
        const reviewDialog = ref(null);
        const review = ref(null);
        const reviewError = ref('');
        const editing = ref({});
        const notice = ref('');
        const history = ref([]);
        const shared = ref(null);
        const issued = ref(null);
        const clientName = ref('');
        const pending = ref(false);
        const loading = ref(false);
        const error = ref('');
        const copied = ref(false);
        const shareField = ref(null);
        const query = ref('');
        const statusFilter = ref('all');
        const statusLabels = { pending: 'Ожидают ответа', changes: 'Запрошены правки', accepted: 'Приняты', expired: 'Просрочены', withdrawn: 'Отозваны' };
        const statusNames = { pending: 'Ожидает ответа', changes: 'Запрошены правки', accepted: 'Принято', expired: 'Срок истёк', withdrawn: 'Отозвано' };
        const draftSaved = ref(false);
        const importDialog = ref(null);
        const importResult = ref(null);
        let nextId = 1;
        const line = (name = '', price = 0) => ({ id: nextId++, name, price, quantity: 1, unit: 'усл.' });
        const terms = { basis: '', price_type: 'fixed', valid_until: '', sender_details: '', sender_contact: '', client_details: '', starts_on: '', ends_on: '', advance_percent: 0, client_record_id: null, vat_mode: 'none', vat_rate: '' };
        const draft = ref({
            ...terms,
            stages: [], payments: [],
            title: 'Разработка сайта',
            sender: 'Студия «Контур»',
            client: 'Компания «Север»',
            lines: [line('Аудит и прототип', 18000), line('Дизайн интерфейса', 25500), line('Адаптивная вёрстка', 16000)],
            discount: 5,
            notes: 'Срок выполнения - 15 рабочих дней после согласования. Оплата в два этапа.',
        });
        const draftKey = 'estimate:draft:v1';
        try {
            const saved = JSON.parse(localStorage.getItem(draftKey));
            if (saved && Array.isArray(saved.lines) && saved.lines.length > 0 && saved.lines.length <= 30) {
                draft.value = { ...terms, stages: [], payments: [], ...saved, lines: saved.lines.map(row => ({ unit: 'усл.', ...row, id: nextId++ })) };
                draftSaved.value = true;
            }
        } catch {}
        watch(draft, value => {
            if (user.value) { draftSaved.value = false; return; }
            try {
                localStorage.setItem(draftKey, JSON.stringify(value));
                draftSaved.value = true;
            } catch { draftSaved.value = false; }
        }, { deep: true });

        const blankDraft = () => ({ ...terms, stages: [], payments: [], title: '', sender: '', client: '', discount: 0, notes: '', lines: [line()] });
        const payload = () => {
            const { lines, stages, payments, ...fields } = draft.value;
            return { ...fields, payments: payments.map(({ label, percent, due_on }) => ({ label, percent, due_on })),
                stages: stages.map(({ key, name, due_on }) => ({ key, name, due_on })),
                lines: lines.map(({ name, quantity, price, unit, stage }) => ({ name, quantity, price, unit, stage })) };
        };

        async function loadWorkspace() {
            const kinds = Object.keys(emptyRecords());
            const lists = await Promise.all(kinds.map(kind => api('/workspace/' + kind)));
            records.value = Object.fromEntries(kinds.map((kind, index) => [kind, lists[index]]));
        }

        const session = api('/session').then(result => {
            user.value = result.user;
            csrf = result.token;
            if (user.value) {
                draft.value = blankDraft();
                return loadWorkspace().then(() => {
                    const profile = records.value.contractors[0];
                    if (profile && !draft.value.sender) selectContractor(profile.id);
                });
            }
        }).catch(failure => { error.value = failure.message; }).finally(() => { sessionReady.value = true; });

        async function authenticate() {
            await send(async () => {
                if (forgot.value) {
                    notice.value = (await api('/session/forgot', { email: accountForm.value.email })).message;
                    accountDialog.value.close();
                    forgot.value = false;
                    return;
                }
                const result = await api('/session/' + (register.value ? 'register' : 'login'), accountForm.value);
                user.value = result.user;
                csrf = result.token;
                accountForm.value = { name: '', email: '', password: '' };
                register.value = false;
                forgot.value = false;
                draftId.value = null;
                issued.value = null;
                accountDialog.value.close();
                await loadWorkspace();
                if (['/history', '/workspace', '/today', '/telegram-inbox'].includes(page.value)) history.value = await api('/proposals');
                if (page.value === '/telegram-inbox') await loadTelegramInbox();
            });
        }

        function resetPassword() {
            return send(async () => {
                const result = await api('/session/reset', { ...resetForm.value, token: route.params.token });
                csrf = result.token;
                user.value = null;
                records.value = emptyRecords();
                accountForm.value.email = resetForm.value.email;
                resetForm.value.password = resetForm.value.password_confirmation = '';
                await navigation.push('/workspace');
                notice.value = result.message;
                accountDialog.value.showModal();
            });
        }

        function signOut() {
            return send(async () => {
                csrf = (await api('/session/logout', {})).token;
                user.value = null;
                records.value = emptyRecords();
                history.value = [];
                telegramInbox.value = [];
                telegramReplies.value = {};
                editing.value = {};
                activeClient.value = null;
                draftId.value = null;
                issued.value = null;
                review.value = null;
                draft.value = blankDraft();
                await navigation.push('/');
            });
        }

        function saveDraft() {
            return send(async () => {
                const row = await api('/workspace/drafts' + (draftId.value ? '/' + draftId.value : ''), {
                    ...payload(), version: draftVersion.value,
                });
                draftId.value = row.id;
                draftVersion.value = row.version;
                records.value.drafts = [row, ...records.value.drafts.filter(item => item.id !== row.id)];
                notice.value = 'Черновик сохранён в кабинете.';
            });
        }

        function openDraft(item) {
            draft.value = { ...terms, stages: [], payments: [], ...item.data,
                payments: (item.data.payments ?? []).map(row => ({ ...row, id: nextId++ })),
                lines: item.data.lines.map(row => ({ ...row, id: nextId++ })) };
            draftId.value = item.id;
            draftVersion.value = item.version;
            issued.value = null;
            navigation.push('/');
        }

        function selectClient(id) {
            const row = records.value.clients.find(item => String(item.id) === id);
            if (!row) return;
            draft.value.client = row.data.name;
            draft.value.client_details = row.data.details ?? '';
            draft.value.client_record_id = row.id;
        }

        function selectContractor(id) {
            const row = records.value.contractors.find(item => String(item.id) === String(id));
            if (!row) return;
            Object.assign(draft.value, { sender: row.data.name, sender_details: row.data.details ?? '', sender_contact: row.data.contact ?? '' });
        }

        async function openClientCard(item) {
            activeClient.value = item.id;
            await nextTick();
            document.querySelector('.client-card')?.scrollIntoView({ block: 'start' });
        }

        function saveTemplate() {
            return send(async () => {
                const data = { ...payload(), template_name: templateName.value };
                const row = await api('/workspace/templates', data);
                records.value.templates.unshift(row);
                templateName.value = '';
                templateDialog.value.close();
                notice.value = 'Шаблон сохранён.';
            });
        }

        function useTemplate(item) {
            if (!window.confirm('Заменить текущий черновик содержимым шаблона?')) return;
            const { template_name, ...data } = item.data;
            const start = data.starts_on || data.stages?.map(stage => stage.due_on).filter(Boolean).sort()[0];
            const now = Date.parse(moscowDay.format(new Date()) + 'T00:00:00Z');
            const offset = start ? now - Date.parse(start + 'T00:00:00Z') : 0;
            const move = date => date ? new Date(Date.parse(date + 'T00:00:00Z') + offset).toISOString().slice(0, 10) : '';
            openDraft({ id: null, version: null, data: { ...data,
                starts_on: move(data.starts_on), ends_on: move(data.ends_on),
                stages: (data.stages ?? []).map(stage => ({ ...stage, due_on: move(stage.due_on) })),
                client: '', client_details: '', client_record_id: null, valid_until: '',
            } });
        }

        function addStage() {
            if (draft.value.stages.length < 10) draft.value.stages.push({ key: crypto.randomUUID?.() ?? 'stage-' + Date.now() + '-' + nextId++, name: '', due_on: '' });
        }
        function removeStage(stage) {
            draft.value.stages = draft.value.stages.filter(item => item.key !== stage.key);
            for (const position of draft.value.lines) {
                if (position.stage === stage.key) position.stage = null;
            }
        }
        function moveStage(index, step) {
            const next = index + step;
            const stages = draft.value.stages;
            if (next < 0 || next >= stages.length) return;
            [stages[index], stages[next]] = [stages[next], stages[index]];
        }

        function saveRecord(kind, form) {
            return send(async () => {
                const row = await api('/workspace/' + kind + (form.id ? '/' + form.id : ''), form);
                records.value[kind] = [row, ...records.value[kind].filter(item => item.id !== row.id)];
                delete editing.value[kind];
                notice.value = 'Запись справочника сохранена.';
            });
        }

        function removeRecord(kind, item) {
            if (!window.confirm('Удалить эту запись?')) return;
            return send(async () => {
                await api('/workspace/' + kind + '/' + item.id, undefined, undefined, 'DELETE');
                records.value[kind] = records.value[kind].filter(row => row.id !== item.id);
                if (kind === 'drafts' && draftId.value === item.id) draftId.value = null;
                if (editing.value[kind]?.id === item.id) delete editing.value[kind];
                notice.value = 'Запись удалена.';
            });
        }

        const preview = computed(() => {
            const lines = draft.value.lines.map(row => ({
                ...row,
                amount: Math.max(0, Math.min(999, Math.trunc(Number(row.quantity) || 0))) * unitPrice(row),
            }));
            const subtotal = lines.reduce((sum, row) => sum + row.amount, 0);
            const percentValue = value => Math.max(0, Math.min(100, Math.trunc(Number(value) || 0)));
            const discount_amount = ratio(subtotal, percentValue(draft.value.discount));
            const discounted = subtotal - discount_amount;
            const rate = draft.value.vat_mode === 'none' ? 0 : percentValue(draft.value.vat_rate);
            const vat_amount = ratio(discounted, rate, draft.value.vat_mode === 'included' ? 100 + rate : 100);
            const vat_base = draft.value.vat_mode === 'included' ? discounted - vat_amount : discounted;
            const total = vat_base + vat_amount;
            const advance = ratio(total, percentValue(draft.value.advance_percent));
            let share = 0, paid = 0;
            const payments = draft.value.payments.map(payment => {
                share += percentValue(payment.percent);
                const cumulative = ratio(total, Math.min(100, share));
                const amount = cumulative - paid;
                paid = cumulative;
                return { ...payment, amount };
            });
            const stages = draft.value.stages.map(stage => ({ ...stage,
                subtotal: lines.filter(row => row.stage === stage.key).reduce((sum, row) => sum + row.amount, 0),
            }));
            return { ...draft.value, stages, lines, subtotal, discount_amount, vat_amount, vat_base,
                total, advance, balance: total - advance, payments, share };
        });
        const shareUrl = computed(() => issued.value && new URL('/p/' + issued.value.token, location.origin).href);
        const proposalNumber = id => 'КП-' + String(id).padStart(4, '0');
        const visibleHistory = computed(() => history.value.filter(item => Boolean(item.archived_at) === archiveView.value));
        const counts = computed(() => visibleHistory.value.reduce((count, item) => {
            count[item.status]++;
            return count;
        }, { pending: 0, changes: 0, accepted: 0, expired: 0, withdrawn: 0 }));
        const filteredHistory = computed(() => {
            const search = query.value.trim().toLocaleLowerCase('ru');
            return visibleHistory.value.filter(item => (statusFilter.value === 'all' || item.status === statusFilter.value)
                && [item.snapshot.title, item.snapshot.client, proposalNumber(item.id)]
                    .some(value => value.toLocaleLowerCase('ru').includes(search)));
        });
        const versions = computed(() => new Map(history.value.map(item => [item.id, item])));
        function changes(item) {
            const previous = versions.value.get(item.revision_of)?.snapshot;
            if (!previous) return [];
            const current = item.snapshot;
            const descriptions = [];
            const sample = (row, doc) => row ? [row.name, row.quantity + ' ' + (row.unit || 'ед.'), formatMoney(unitPrice(row)),
                doc.stages?.find(stage => stage.key === row.stage)?.name].filter(Boolean).join(' · ') : 'Нет позиции';
            for (let index = 0; index < Math.max(previous.lines.length, current.lines.length); index++) {
                const before = sample(previous.lines[index], previous);
                const after = sample(current.lines[index], current);
                if (before !== after) descriptions.push({ label: 'Позиция ' + (index + 1), before, after });
            }
            const stageText = stage => stage ? [stage.name, stage.due_on?.split('-').reverse().join('.')].filter(Boolean).join(' · ') : 'Нет этапа';
            const beforeStages = previous.stages ?? [], afterStages = current.stages ?? [];
            for (let index = 0; index < Math.max(beforeStages.length, afterStages.length); index++) {
                const before = stageText(beforeStages[index]), after = stageText(afterStages[index]);
                if (before !== after) descriptions.push({ label: 'Этап ' + (index + 1), before, after });
            }
            for (const [key, label] of Object.entries({
                title: 'Проект', sender: 'Подрядчик', client: 'Заказчик', sender_details: 'Реквизиты подрядчика',
                client_details: 'Реквизиты заказчика', starts_on: 'Начало работ', ends_on: 'Окончание работ',
                valid_until: 'Действительно по', price_type: 'Вид цены', discount: 'Скидка', total: 'Итого', basis: 'Основание', notes: 'Условия',
                advance_percent: 'Аванс, %',
                vat_mode: 'Режим НДС', vat_rate: 'Ставка НДС', vat_amount: 'Сумма НДС',
            })) {
                const readable = value => value == null || value === '' ? 'Не указано'
                    : ['total', 'vat_amount'].includes(key) ? formatMoney(value)
                    : ['discount', 'vat_rate'].includes(key) ? value + '%'
                    : key === 'vat_mode' ? ({ none: 'Без НДС', added: 'Сверху', included: 'Включён' })[value]
                    : key === 'price_type' ? (value === 'fixed' ? 'Твёрдая' : 'Приблизительная')
                    : ['starts_on', 'ends_on', 'valid_until'].includes(key) ? value.split('-').reverse().join('.') : value;
                const before = readable(previous[key]), after = readable(current[key]);
                if (before !== after) descriptions.push({ label, before, after });
            }
            return descriptions;
        }

        function duplicate(item) {
            if (!window.confirm('Заменить текущий черновик копией этого предложения?')) return;
            const { subtotal, total, discount_amount, advance, balance, vat_base, vat_amount, valid_until, lines, payments, ...fields } = item.snapshot;
            draft.value = { ...terms, stages: [], ...fields, revision_of: item.id,
                client_record_id: records.value.clients.some(row => row.id === item.client_record_id) ? item.client_record_id : null,
                payments: (payments ?? []).map(({ label, percent, due_on }) => ({ label, percent, due_on, id: nextId++ })),
                lines: lines.map(({ name, price, quantity, unit, stage }) => ({
                ...line(name, price), quantity, unit: unit ?? 'усл.', stage,
            })) };
            issued.value = null;
            draftId.value = null;
            navigation.push('/');
        }

        function newDraft() {
            if (!window.confirm('Очистить текущий черновик?')) return;
            draft.value = blankDraft();
            const profile = records.value.contractors[0];
            if (profile) selectContractor(profile.id);
            issued.value = null;
            draftId.value = null;
            error.value = '';
            navigation.push('/');
        }

        watch(page, async (path, _, onCleanup) => {
            const controller = new AbortController();
            onCleanup(() => controller.abort());
            shared.value = null;
            clientName.value = '';
            changeComment.value = '';
            lineComments.value = {};
            error.value = '';
            loading.value = path !== '/';
            try {
                await session;
                if (controller.signal.aborted) return;
                if (path === '/') {
                    const [items, proposals] = await Promise.all([
                        api('/catalog', undefined, controller.signal), api('/proposals', undefined, controller.signal),
                    ]);
                    catalog.value = items;
                    history.value = proposals;
                } else if (['/history', '/workspace', '/today', '/telegram-inbox'].includes(path)) {
                    history.value = await api('/proposals', undefined, controller.signal);
                    if (path === '/telegram-inbox' && user.value) await loadTelegramInbox(controller.signal);
                } else if (path.startsWith('/p/')) {
                    shared.value = await api('/proposals/' + encodeURIComponent(route.params.token), undefined, controller.signal);
                }
            } catch (failure) {
                if (!controller.signal.aborted) error.value = failure.message;
            } finally {
                if (!controller.signal.aborted) loading.value = false;
            }
        }, { immediate: true });

        function addLine(item) {
            if (draft.value.lines.length < 30) draft.value.lines.push(item ? { ...line(item.name, item.price), unit: item.unit ?? 'усл.' } : line());
        }
        async function send(action) {
            if (pending.value) return;
            pending.value = true;
            error.value = '';
            notice.value = '';
            try { await action(); }
            catch (failure) { error.value = failure.message; }
            finally { pending.value = false; }
        }
        function openReview() {
            return send(async () => {
                const input = payload();
                const result = await api('/review', input);
                review.value = { ...result, input };
                reviewError.value = '';
                reviewDialog.value.showModal();
            });
        }
        async function issue() {
            if (pending.value || !review.value) return;
            pending.value = true;
            reviewError.value = '';
            try {
                issued.value = await api('/proposals', review.value.input);
                copied.value = false;
                reviewDialog.value.close();
                await copyLink();
            } catch (failure) { reviewError.value = failure.message; }
            finally { pending.value = false; }
        }
        function accept() {
            const token = shared.value.token;
            return send(async () => {
                const result = await api('/proposals/' + token + '/accept', { name: clientName.value });
                if (shared.value?.token === token) shared.value = result;
            });
        }
        function requestChanges() {
            const token = shared.value.token;
            return send(async () => {
                const positions = Object.entries(lineComments.value).filter(([, comment]) => comment.trim())
                    .map(([index, comment]) => ({ index: Number(index), comment: comment.trim() }));
                const result = await api('/proposals/' + token + '/changes', { comment: changeComment.value, positions });
                if (shared.value?.token === token) shared.value = result;
                changeComment.value = '';
            });
        }
        function toggleArchive(item) {
            return send(async () => {
                history.value = await api('/proposals/' + item.token + '/archive', { archived: !item.archived_at });
                notice.value = item.archived_at ? 'Смета возвращена из архива.' : 'Смета перемещена в архив.';
            });
        }

        function refreshReceiptData(data) {
            receiptData.value = data;
            const row = history.value.find(item => item.id === receiptItem.value.id);
            if (row) row.payment_summary = data.summary;
        }

        function openReceipts(item) {
            return send(async () => {
                const data = await api('/proposals/' + item.token + '/receipts');
                receiptItem.value = item;
                refreshReceiptData(data);
                receiptInput.value = { index: data.summary.schedule.findIndex(payment => payment.remaining > 0),
                    amount: '', paid_on: moscowDay.format(new Date()), reference: '', request_id: crypto.randomUUID() };
                receiptsDialog.value.showModal();
            });
        }

        function saveReceipt() {
            return send(async () => {
                const { index, amount, paid_on, reference, request_id } = receiptInput.value;
                const cents = toCents(amount, 40_000_000_000_000);
                if (!cents) throw Error('Укажите сумму оплаты в рублях и копейках.');
                refreshReceiptData(await api('/proposals/' + receiptItem.value.token + '/receipts', {
                    payment_index: index, amount: cents, paid_on, reference, request_id,
                }));
                receiptInput.value.index = receiptData.value.summary.schedule.findIndex(payment => payment.remaining > 0);
                receiptInput.value.amount = receiptInput.value.reference = '';
                receiptInput.value.request_id = crypto.randomUUID();
            });
        }

        function voidReceipt(entry) {
            if (!window.confirm('Отменить запись об оплате?')) return;
            return send(async () => {
                refreshReceiptData(await api('/proposals/' + receiptItem.value.token + '/receipts/' + entry.id + '/void', {}));
                if (receiptInput.value.index === -1) receiptInput.value.index = entry.payment_index;
            });
        }
        function withdraw(item) {
            if (!window.confirm('Отозвать предложение? По этой ссылке клиент больше не сможет сообщить о согласии.')) return;
            return send(async () => {
                try {
                    const changed = await api('/proposals/' + item.token + '/withdraw', {});
                    history.value = history.value.map(row => row.id === changed.id ? { ...row, ...changed } : row);
                    if (issued.value?.id === changed.id) issued.value = changed;
                } catch (failure) {
                    if (page.value === '/history') history.value = await api('/proposals');
                    throw failure;
                }
            });
        }
        async function copyLink() {
            try {
                await navigator.clipboard.writeText(shareUrl.value);
                copied.value = true;
            } catch {
                shareField.value?.select();
            }
        }

        function addPayment() {
            if (draft.value.payments.length >= 12) return;
            draft.value.advance_percent = 0;
            draft.value.payments.push({ id: nextId++, label: '', percent: '' });
        }

        function inspectImport(file) {
            if (!file) return;
            importResult.value = null;
            return send(async () => {
                const data = new FormData();
                data.append('file', file);
                importResult.value = await api('/import-positions', data);
            });
        }

        const importBase = computed(() => draft.value.lines.length === 1 && !draft.value.lines[0].name
            && !unitPrice(draft.value.lines[0]) ? 0 : draft.value.lines.length);
        function applyImport() {
            if (!importResult.value || importResult.value.errors.length || !importResult.value.lines.length
                || importBase.value + importResult.value.lines.length > 30) return;
            if (importBase.value === 0) draft.value.lines = [];
            draft.value.lines.push(...importResult.value.lines.map(row => ({ ...row, id: nextId++ })));
            importDialog.value.close();
            importResult.value = null;
        }

        function events(item) {
            const entries = [{ at: item.created_at, label: 'Смета выпущена' }];
            if (item.revision_of) entries[0].label += ' на основе ' + proposalNumber(item.revision_of);
            if (item.revision_requested_at) entries.push({ at: item.revision_requested_at, label: 'Клиент запросил правки', note: item.revision_comment });
            if (item.accepted_at) entries.push({ at: item.accepted_at, label: 'Клиент сообщил о согласии', note: item.accepted_by });
            if (item.withdrawn_at) entries.push({ at: item.withdrawn_at, label: 'Смета отозвана' });
            for (const version of history.value.filter(row => row.revision_of === item.id)) {
                entries.push({ at: version.created_at, label: 'Выпущена версия ' + proposalNumber(version.id), token: version.token });
            }
            return entries.sort((a, b) => a.at.localeCompare(b.at));
        }
        const dateTime = value => new Intl.DateTimeFormat('ru-RU', { dateStyle: 'short', timeStyle: 'short',
            timeZone: 'Europe/Moscow' }).format(new Date(value.replace(' ', 'T') + 'Z'));

        return { page, catalog, history, shared, issued, clientName, pending, loading, error, draft, preview, shareUrl, copied,
            shareField, proposalNumber, formatMoney, addLine, issue, accept, copyLink, query, filteredHistory, draftSaved,
            duplicate, newDraft, withdraw, statusFilter, statusLabels, statusNames, counts,
            pdfUrl: item => '/api/proposals/' + item.token + '/pdf', user, sessionReady, records, draftId, accountForm, register,
            accountDialog, authenticate, signOut, saveDraft, openDraft, selectClient, saveRecord, removeRecord, editing, notice,
            reviewDialog, review, reviewError, openReview, versions, changes, templateName, templateDialog, saveTemplate, useTemplate,
            activeClient, clientCard, clientProposals, clientDrafts, followUps, changeComment, requestChanges, addStage, removeStage,
            forgot, resetForm, resetPassword, moveStage, openClientCard, addPayment, unitPrice, toCents, importDialog,
            importResult, inspectImport, applyImport, importBase, events, dateTime, selectContractor, archiveView, visibleHistory,
            lineComments, toggleArchive, receiptsDialog, receiptItem, receiptData, receiptInput, openReceipts, saveReceipt,
            voidReceipt, xlsxUrl: item => '/api/proposals/' + item.token + '/xlsx',
            telegramInbox, telegramReplies, loadTelegramInbox, replyTelegram,
            refreshTelegram: () => send(loadTelegramInbox) };
    },
}).use(router).mount('#app');
