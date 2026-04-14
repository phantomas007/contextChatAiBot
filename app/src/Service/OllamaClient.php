<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class OllamaClient
{
    private const int MAX_DURATION = 600;
    private const string RUNPOD_BASE_URL = 'https://api.runpod.ai/v2';

    private readonly string $telegramBotLink;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire(env: 'OLLAMA_URL')]
        private readonly string $ollamaUrl,
        #[Autowire(env: 'OLLAMA_MODEL')]
        private readonly string $model,
        #[Autowire(env: 'default::TELEGRAM_BOT_LINK')]
        string $telegramBotLinkRaw,
        #[Autowire(env: 'bool:default:use_local_ollama_default:USE_LOCAL_OLLAMA')]
        private readonly bool $useLocalOllama,
        #[Autowire(env: 'default::RUNPOD_API_KEY')]
        private readonly string $runpodApiKey,
        #[Autowire(env: 'default::RUNPOD_ENDPOINT_ID')]
        private readonly string $runpodEndpointId,
        #[Autowire(env: 'default::RUNPOD_ENDPOINT_ID_AGGREGATION')]
        private readonly string $runpodEndpointIdAggregation,
        #[Autowire(env: 'default::RUNPOD_ENDPOINT_ID_DAILY')]
        private readonly string $runpodEndpointIdDaily,
    ) {
        $this->telegramBotLink = self::normalizeTelegramBotLink($telegramBotLinkRaw);
    }

    private static function normalizeTelegramBotLink(string $raw): string
    {
        $raw = trim($raw);
        if ('' === $raw) {
            return '';
        }
        if (str_starts_with($raw, 'http://') || str_starts_with($raw, 'https://')) {
            return $raw;
        }
        if (preg_match('#^t\.me/#i', $raw)) {
            return 'https://'.$raw;
        }
        $name = ltrim($raw, '@');

        return 'https://t.me/'.$name;
    }

    /**
     * Саммари с расширенным промптом (Ya2): итог по теме, развёрнутые примеры, требования к заголовкам.
     *
     * @param array<int, array{username: string|null, text: string, date: string}> $messages
     */
    public function summarizeYa2(array $messages, string $groupTitle, int $messagesCount = 50): string
    {
        $formatted = implode("\n", array_map(
            static fn (array $m): string => \sprintf(
                '[%s] %s: %s',
                $m['date'],
                $m['username'] ?? 'Аноним',
                $m['text'],
            ),
            $messages,
        ));

        $prompt = <<<PROMPT
            Ты — редактор живого и полезного дайджеста Telegram-чата «{$groupTitle}».
            Твоя задача — выделить СУТЬ обсуждений и сделать краткий, понятный и интересный пересказ последних сообщений чата.

            КРИТИЧНО: Отвечай ТОЛЬКО на русском языке.
            КРИТИЧНО: НЕ нумеруй темы.

            Определи 3–6 основных тем переписки.
            Для каждой темы сделай:
            — цепляющий заголовок
            — живой пересказ 2–5 предложений
            - Итоги обсуждения по теме кратко и понятно

            Выведи только темы с пересказами, без вступления и заключения.

            ────────────────────

            ТРЕБОВАНИЯ К ЗАГОЛОВКУ

            Название темы должно:
            - передавать конкретную суть обсуждения
            - описывать проблему или вопрос
            - быть похоже на заголовок YouTube
            - содержать 5–10 слов
            - быть уникальным
            - начинаться с эмодзи по смыслу темы
            - заканчиваться символом ✉️

            Формат:

            эмодзи по смыслу Текст темы ✉️

            Плохо:
            Выбор автомобиля

            Хорошо:
            🚗 Какую б/у машину взять до миллиона рублей ✉️

            ────────────────────

            ТРЕБОВАНИЯ К ПЕРЕСКАЗУ

            Пересказ должен:
            - быть живым и естественным в формате  Username1 спрашивает  Username2 отвечает  Username3 делится опытом .. Подведи итоги обсуждения
            - содержать конкретные действия участников
            - показывать кто спрашивает и кто отвечает
            - передавать смысл диалога
            - быть связным текстом
            - состоять из 2–5 предложений
            - подводить итог обсуждения по теме

            Правильный стиль:

            Alexey_m ищет машину и выбирает между Kia и Hyundai. Igor_nn советует смотреть на состояние автомобиля. Sergey_auto рекомендует диагностику перед сделкой. В итоге советуют не брать авто без проверки.

            Неправильный стиль:

            Участники обсуждают выбор автомобиля и делятся мнениями.

            ────────────────────

            СТРУКТУРА

            эмодзи Название темы ✉️

            Username1 спрашивает...
            Username2 отвечает...
            Username3 делится опытом...
            Подведи итоги обсуждения кратко

            Пустая строка между заголовком и текстом обязательна.

            ────────────────────

            ПРИМЕРЫ

            Пример — чат автомобилистов

            🚗 Какую б/у машину взять до миллиона рублей ✉️

            Alexey_m ищет автомобиль в бюджете до миллиона и выбирает между Kia и Hyundai. Igor_nn советует смотреть на состояние машины и делится опытом покупки с проблемным двигателем. Sergey_auto рекомендует делать диагностику перед сделкой. В итоге большинство советует не брать авто без проверки.

            🔧 Нужно ли проверять авто на СТО перед покупкой ✉️

            Misha_k спрашивает, обязательно ли ехать на диагностику перед покупкой машины. Anton_msk говорит, что хороший мастер на обычном СТО справится. Kirill_t делится случаем, когда диагностика выявила скрытые проблемы. В итоге советуют не покупать авто без проверки.

            ────────────────────

            Пример — чат жильцов

            🔊 Можно ли шуметь в квартире до одиннадцати вечера ✉️

            Julia_auto жалуется на громкую музыку у соседей и спрашивает, законно ли это. Artem_k объясняет, что шум разрешён до одиннадцати вечера. Olga_spb советует сначала поговорить с соседями лично. В итоге участники рекомендуют решать вопрос мирно.

            ────────────────────

            Пример — чат путешественников

            ✈️ Куда поехать на неделю с небольшим бюджетом ✉️

            Natasha_v ищет недорогой вариант отдыха на неделю. Pasha_pro советует Турцию или Грузию. Sveta_ok предлагает Балканы и делится маршрутом. В итоге сходятся, что лучше выбирать направление с дешёвыми билетами и жильём.

            ────────────────────

            Пример — рабочий чат

            📊 Перенос дедлайна квартального отчёта на неделю ✉️

            Manager_oleg сообщает, что сроки отчёта сдвигаются на неделю. Analyst_vera уточняет формат итогового документа. Kolya_sales обещает прислать данные по продажам к середине недели. В итоге команда перераспределяет задачи и корректирует сроки.

            ────────────────────
            Пример — чат техники

            📱 Стоит ли переходить на iPhone после Android ✉️

            Sergey_auto думает перейти на iPhone и сомневается в удобстве. Igor_nn говорит, что привыкнуть можно за пару недель. Misha_k хвалит экосистему Apple и синхронизацию устройств. В итоге советуют попробовать и не бояться перехода.

            ────────────────────
            Пример — чат жильцов дома

            🗣️ Как не ссориться с соседями ✉️

            Alexey_m интересуется, как поддерживать хорошие отношения с соседями. Marina_k делится опытом конфликта из-за музыки. В итоге советуют решать бытовые вопросы мирным путём и уважительно.
            ────────────────────
            
            Конец примеров.

            Теперь напиши пересказ переписки ниже строго по такому же формату.

            ────────────────────

            ПРАВИЛА

            - 3–6 тем
            - объединяй только действительно близкие сообщения
            - подводи итог обсуждения - кратко и понятно
            - если тем меньше — не выдумывай
            - Username оставляй как есть первая буква большая 
            - Username всегда латиницей с большой бкувы без скобок
            - каждая тема содержит 2–5 предложений
            - приветствия и оффтоп игнорировать
            - после последней темы ничего не писать

            ────────────────────

            ЗАПРЕЩЕНО

            - нумеровать темы
            - использовать markdown
            - писать заголовок и текст в одну строку
            - писать все темы одним абзацем
            - писать только заголовки
            - писать вступления
            - писать заключения
            - писать "пересказ"
            - писать "темы обсуждения"
            - писать "в переписке обсуждается"
            - писать "участники обсуждают"
            - писать "обсуждение касается"
            - писать "пользователи обсуждают"
            - писать темы как категории вместо сути
            - писать однословные темы
            - писать мета-комментарии
            - писать Username в скобках(), с маленькой буквы 
            - неисполльзовать выделение текста в квдратные скобки [ текст ]

            ────────────────────

            Переписка:
            {$formatted}
            PROMPT;

        $result = $this->generate($prompt);

        return $result;
    }

    /**
     * Агрегация нескольких кирпичей в один count-based дайджест (порог 50+ сообщений).
     *
     * @param string[] $cleanSummaries саммари без header/footer обвязки
     */
    public function summarizeCountBased(array $cleanSummaries, string $groupTitle, int $countThreshold): string
    {
        $formatted = implode("\n\n---\n\n", array_map(
            static fn (int $i, string $s): string => \sprintf("Дайджест %d:\n%s", $i + 1, $s),
            array_keys($cleanSummaries),
            $cleanSummaries,
        ));

        $bricksCount = \count($cleanSummaries);
        $prompt = <<<PROMPT
            Ты — редактор живого и полезного дайджеста Telegram-чата «{$groupTitle}». Тебе даны {$bricksCount} тем обсуждений одного чата.

            ТВОЯ РОЛЬ:
            Ты не пересказываешь всё подряд — ты ЖЁСТКО отбираешь только полезные обсуждения с реальной ценностью.

            ЗАДАЧА:

            1. УДАЛИ темы, если:
               - нет ответов
               - участвует только один человек
               - нет конкретных советов, опыта или кейсов
               - нет внятного итога
               - обсуждение осталось на уровне вопроса
               - есть фразы типа "нет ответа", "непонятно", "обсуждение не развилось"

               ❗ Такие темы полностью игнорируй — НЕ УПОМИНАЙ ИХ вообще

            2. ОБЪЕДИНЯЙ темы:
               - одинаковые или похожие темы → строго в одну
               - повторяющиеся выводы → не дублировать
               - например:
                 проверка авто / диагностика / осмотр → одна тема
                 торг / цена / скидка → одна тема
                 выбор авто / какую модель взять → одна тема

            3. ОСТАВЬ только сильные темы:
               - от 3 до 8 тем
               - если слабых много → лучше оставить 3–4, чем тянуть мусор
               - если нет хороших тем → верни меньше, но качественно
               - перед названием темы обязательно используй эмодзи по смыслу

            ФОРМАТ:

            ЭМОДЗИ Название темы ✉️

            Username1 спрашивает...
            Username2 отвечает...
            Username3 делится опытом...
            Краткий итог с конкретным советом

            ТРЕБОВАНИЯ:

            - 2–5 предложений
            - максимум 6, если тема объединённая
            - текст как живой диалог (кто что сказал)
            - БЕЗ воды и абстракций
            - ТОЛЬКО конкретика (что делать, как делать, советы, примеры)

            ❗ ЗАПРЕЩЕНО писать:
            - "обсуждение не дало ответа"
            - "конкретных рекомендаций нет"
            - "тема не раскрыта"
            - любые размытые или пустые формулировки
            - не повторяй одинаковые эмодзи везде
            - [] для эмодзи 

            👉 Если нет конкретики — УДАЛИ тему

            Username:
            - с большой буквы
            - без скобок
            - не менять ник

            Заголовок:
            - 5–10 слов
            - конкретный и цепляющий
            - как YouTube (чтобы хотелось кликнуть)
            - отражает суть проблемы или выгоды
            - без общих слов типа "обсуждение", "вопрос"
            - используй эмодзи по смыслу темы перед заголовком

            ОБЯЗАТЕЛЬНО:

            - только русский язык
            - без markdown, ** и лишних символов
            - не дублируй темы
            - не пиши одинаковые смыслы разными словами

            ФИНАЛЬНАЯ ПРОВЕРКА (ОБЯЗАТЕЛЬНА):

            Перед ответом проверь:
            - тем от 3 до 8
            - в каждой теме есть:
              → вопрос
              → ответ
              → опыт или совет
              → итог
            - нет слабых или пустых тем
            - нет фраз про отсутствие ответов
            - нет дублей

            ПРИМЕР:

            🚗 Как выбрать б/у авто и не попасть на ремонт ✉️

            Alexey_m ищет машину и сомневается в выборе. Igor_nn советует проверять авто на СТО перед покупкой. Sergey_auto делится опытом покупки с проблемами из-за пропущенной диагностики. В итоге советуют всегда делать полную проверку перед сделкой.

            ---

            {$formatted}
            PROMPT;

        $result = $this->generate($prompt, useAggregationEndpoint: true);

        return $result;
    }

    /**
     * Суточная агрегация — агрессивная компрессия N кирпичей в обзор дня.
     *
     * @param string[] $cleanSummaries саммари без header/footer обвязки
     */
    public function summarizeDaily(array $cleanSummaries, string $groupTitle): string
    {
        $formatted = implode("\n\n---\n\n", array_map(
            static fn (int $i, string $s): string => \sprintf("Дайджест %d:\n%s", $i + 1, $s),
            array_keys($cleanSummaries),
            $cleanSummaries,
        ));

        $bricksCount = \count($cleanSummaries);

        $prompt = <<<PROMPT
            Ты — редактор Telegram-дайджеста «{$groupTitle}».

            Тебе дано {$bricksCount} дайджестов за день.
            Каждый содержит несколько тем.

            ТВОЯ ЗАДАЧА:
            Сделать ОДИН итоговый дайджест за день.

            ────────────────────

            АЛГОРИТМ:

            1. Объедини похожие темы в одну.
            (пример: покупка авто + проверка авто + торг = одна тема)

            ВАЖНО:
            Если темы пересекаются по смыслу — ОБЯЗАТЕЛЬНО объединяй их.

            2. УДАЛИ слабые темы:

            - только вопрос без ответа
            - нет конкретных советов
            - нет диалога (1 участник)
            - тема упомянута один раз

            3. Выбери 4–7 САМЫХ ВАЖНЫХ тем за день.

            4. НЕ добавляй ничего от себя.
            Используй только факты из текста.

            КРИТИЧНО:
            Запрещено добавлять:
            - новые факты
            - новые имена
            - новые детали

            Если информации мало — пиши кратко.

            ────────────────────

            ФОРМАТ:

            Эмодзи Заголовок ✉️

            Username1 спрашивает...
            Username2 отвечает...
            Username3 делится опытом...
            Короткий итог: ...

            (3–5 предложений)

            СТРОГО СОБЛЮДАЙ ФОРМАТ:
            <пустая строка>
            Заголовок
            <пустая строка>
            Текст

            ────────────────────

            ТРЕБОВАНИЯ:

            - без нумерации
            - без вступлений и выводов
            - один блок (не несколько)
            - Username с большой буквы, латиницей
            - не придумывать факты
            - не писать "Дайджест за день"
            - не использовать символы # или markdown-заголовки
            - не использовать символы * или нумерацию

            ────────────────────

            ПОСЛЕ ВСЕХ ТЕМ:

            Добавь блок:

            Инсайт дня:

            - ровно 1 пункт
            - короткий совет или предупреждение, до 12 слов
            - инсайт должен объединять минимум 2 темы
            - звучит как сильный тезис

            Пример:
            - "Без проверки документов б/у авто — лотерея"
            - "Дешёвые поездки чаще обсуждают, чем реально планируют"

            ВАЖНО:

            - инсайт не повторяет текст тем
            - не добавляет новые факты
            - ёмко и цепляюще

            ────────────────────

            Сначала подумай (не выводи), какие темы объединить и какие удалить.
            Потом выведи только финальный результат.

            ────────────────────

            {$formatted}
            PROMPT;

        $result = $this->generate($prompt, numCtx: 16384, numPredict: 3000, useDailyEndpoint: true);

        return $result;
    }

    /**
     * Блок подвала для публикации в Telegram (не хранится в contexts / aggregated).
     */
    public function digestPublicationFooter(): string
    {
        $mention = self::telegramBotMentionFromLink($this->telegramBotLink);
        if ('' === $mention) {
            return '';
        }

        return "\n\n"
            .str_repeat('━', 10)
            ."\n📩 Саммари в личку"
            ."\n🤖 Задать вопрос ИИ → {$mention}";
    }

    /**
     * Убирает header/footer обвязку из саммари кирпича, оставляя только контент тем.
     */
    public function stripSummaryWrapper(string $summary): string
    {
        $summary = preg_replace('/━+\n(?:📋|🔥) Context[^\n]*\n━+\n?/', '', $summary) ?? $summary;

        return trim($this->stripDigestPublicationFooter($summary));
    }

    /**
     * Убирает подвал «Саммари в личку» / старый «Не трать время» (для строк из БД и перед публикацией).
     */
    public function stripDigestPublicationFooter(string $summary): string
    {
        $summary = preg_replace('/\n*─+\nНе трать время.*$/su', '', $summary) ?? $summary;
        $summary = preg_replace('/\n*━+\n📩 Саммари в личку →.*$/su', '', $summary) ?? $summary;

        return rtrim($summary);
    }

    private static function telegramBotMentionFromLink(string $link): string
    {
        if ('' === $link) {
            return '';
        }
        if (preg_match('~^https?://t\.me/([^/?#]+)~i', $link, $m)) {
            return '@'.$m[1];
        }

        return '';
    }

    private function generate(string $prompt, int $numCtx = 8192, int $numPredict = 2500, bool $useAggregationEndpoint = false, bool $useDailyEndpoint = false): string
    {
        if ($this->useLocalOllama) {
            return $this->generateLocal($prompt, $numCtx, $numPredict);
        }

        $endpointId = $this->runpodEndpointId;
        if ($useDailyEndpoint && '' !== $this->runpodEndpointIdDaily) {
            $endpointId = $this->runpodEndpointIdDaily;
        } elseif ($useAggregationEndpoint && '' !== $this->runpodEndpointIdAggregation) {
            $endpointId = $this->runpodEndpointIdAggregation;
        }

        return $this->generateRunpod($prompt, $endpointId);
    }

    private function generateLocal(string $prompt, int $numCtx, int $numPredict): string
    {
        $response = $this->httpClient->request('POST', $this->ollamaUrl.'/api/generate', [
            'timeout' => self::MAX_DURATION,
            'max_duration' => self::MAX_DURATION,
            'json' => [
                'model' => $this->model,
                'prompt' => $prompt,
                'stream' => true,
                'options' => [
                    'num_ctx' => $numCtx,
                    'num_predict' => $numPredict,
                ],
            ],
        ]);

        $result = '';

        foreach ($this->httpClient->stream($response) as $chunk) {
            $content = $chunk->getContent();
            if ('' === $content) {
                continue;
            }

            foreach (explode("\n", $content) as $line) {
                $line = trim($line);
                if ('' === $line) {
                    continue;
                }

                /** @var array{response?: string, done?: bool, error?: string} $data */
                $data = json_decode($line, true) ?? [];

                if (isset($data['error'])) {
                    throw new \RuntimeException('Ollama error: '.$data['error']);
                }

                $result .= $data['response'] ?? '';

                if ($data['done'] ?? false) {
                    break 2;
                }
            }
        }

        return trim($result);
    }

    private function generateRunpod(string $prompt, string $endpointId): string
    {
        $baseUrl = self::RUNPOD_BASE_URL.'/'.$endpointId;

        $response = $this->httpClient->request('POST', $baseUrl.'/run', [
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer '.$this->runpodApiKey,
            ],
            'json' => [
                'input' => [
                    'prompt' => $prompt,
                ],
            ],
        ]);

        $data = $response->toArray();
        $jobId = $data['id'] ?? null;

        if (!$jobId) {
            throw new \RuntimeException('Runpod error: no job id in response');
        }

        $maxAttempts = 120;
        $sleepSeconds = 5;

        for ($i = 0; $i < $maxAttempts; ++$i) {
            $statusResponse = $this->httpClient->request('GET', $baseUrl.'/status/'.$jobId, [
                'timeout' => 30,
                'headers' => [
                    'Authorization' => 'Bearer '.$this->runpodApiKey,
                ],
            ]);

            $statusData = $statusResponse->toArray();
            $status = $statusData['status'] ?? '';

            if ('COMPLETED' === $status) {
                $output = $statusData['output'] ?? [];

                // Runpod Ollama worker: output = [{ choices: [{ text: "..." }] }]
                if (\is_array($output) && isset($output[0]['choices'][0]['text'])) {
                    $result = $output[0]['choices'][0]['text'];
                } else {
                    $result = $output['response'] ?? $output['generated_text'] ?? $output['text'] ?? '';
                }

                return trim((string) $result);
            }

            if ('FAILED' === $status) {
                $error = $statusData['error'] ?? $statusData['output'] ?? 'Unknown error';
                throw new \RuntimeException('Runpod job failed: '.(\is_string($error) ? $error : json_encode($error)));
            }

            sleep($sleepSeconds);
        }

        throw new \RuntimeException('Runpod job timeout: exceeded '.$maxAttempts.' status checks');
    }
}
