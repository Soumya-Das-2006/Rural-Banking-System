<?php
require_once __DIR__ . '/../InternetBanking/core/ai.php';

function expect_model($actual, $expected, $case)
{
    if ($actual !== $expected) {
        throw new RuntimeException('Model test failed: ' . $case);
    }
}

expect_model(rb_ai_model(''), 'openai/gpt-oss-20b', 'empty model uses current default');
expect_model(rb_ai_model('llama-3.3-70b-versatile'), 'openai/gpt-oss-20b', 'retired Llama model is replaced');
expect_model(rb_ai_model('llama-3.1-8b-instant'), 'openai/gpt-oss-20b', 'retired Llama instant model is replaced');
expect_model(rb_ai_model('openai/gpt-oss-20b'), 'openai/gpt-oss-20b', 'current model is preserved');

echo "Groq model configuration tests passed.\n";
