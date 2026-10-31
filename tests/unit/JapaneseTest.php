<?php
use PHPUnit\Framework\TestCase;

class JapaneseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        require_once MEEL_ROOT . '/modules/core/japanese.php';
    }

    protected function skipIfNoMecab(): void
    {
        if (!function_exists('meel_mecab_available') || !meel_mecab_available()) {
            $this->markTestSkipped('mecab tidak tersedia — test romaji di-skip');
        }
    }

    public function testGetRomajiNameEmptyString(): void
    {
        $this->assertSame('untitled', getRomajiName(''));
    }

    public function testGetRomajiNameWithLatinText(): void
    {
        $result = getRomajiName('hello world');
        $this->assertStringContainsString('hello', $result);
    }

    public function testGetRomajiNameWithSpecialChars(): void
    {
        $this->skipIfNoMecab();

        $result = getRomajiName('初音ミク【テスト】');

        $this->assertStringContainsString('hatsune', $result);
    }

    public function testAnalyzeJapaneseTextEmpty(): void
    {
        $result = analyzeJapaneseText('');
        $this->assertSame('untitled-media', $result['romaji']);
        $this->assertSame('', $result['english']);
    }

    public function testAnalyzeJapaneseTextBasic(): void
    {
        $result = analyzeJapaneseText('テスト');
        $this->assertArrayHasKey('romaji', $result);
        $this->assertArrayHasKey('english', $result);
        $this->assertIsString($result['romaji']);
        $this->assertIsString($result['english']);
    }

    public function testAnalyzeJapaneseTextWithAlias(): void
    {
        $this->skipIfNoMecab();
        $result = analyzeJapaneseText('プロジェクトセカイ カラフルステージ!');
        $this->assertStringContainsString('Project Sekai', $result['english']);
        $this->assertStringContainsString('Colorful Stage', $result['english']);
    }

    public function testAnalyzeJapaneseTextWithTouhouAlias(): void
    {
        $result = analyzeJapaneseText('東方プロジェクト ボスラッシュ');
        $this->assertStringContainsString('Touhou', $result['english']);
    }

    public function testAnalyzeJapaneseTextWithNightcordAlias(): void
    {
        $result = analyzeJapaneseText('25時、ナイトコードで。 歌ってみた');
        $this->assertStringContainsString('Nightcord at 25:00', $result['english']);
    }

    public function testAnalyzeJapaneseTextWithCharacterAlias(): void
    {
        $result = analyzeJapaneseText('天馬司 & 鳳えむ');
        $this->assertStringContainsString('Tenma Tsukasa', $result['english']);
        $this->assertStringContainsString('Otori Emu', $result['english']);
    }

    public function testAnalyzeJapaneseTextWithNicknameAlias(): void
    {
        $result = analyzeJapaneseText('プロセカ ワンオポ');
        $this->assertStringContainsString('Project Sekai', $result['english']);
        $this->assertStringContainsString('Wonderlands x Showtime', $result['english']);
    }

    public function testAnalyzeJapaneseTextWithEnsembleStarsAlias(): void
    {
        $result = analyzeJapaneseText('あんさんぶるスターズ コラボ');
        $this->assertStringContainsString('Ensemble Stars', $result['english']);
    }

    public function testAnalyzeJapaneseTextDoesNotGlossParticlesAsHomophones(): void
    {
        $this->skipIfNoMecab();

        $result = analyzeJapaneseText('君が飛び降りるのならば');
        $this->assertStringContainsString('kimi-ga-tobioriru-no-nara-ba', $result['romaji']);
        $this->assertStringNotContainsString('moth', $result['english']);
        $this->assertStringNotContainsString('indicates possessive', $result['english']);
        $this->assertStringNotContainsString('place', $result['english']);
    }

    public function testAnalyzeJapaneseTextFullCoverAliasWins(): void
    {
        $result = analyzeJapaneseText('君が飛び降りるのならば');
        $this->assertSame("In case you're gonna jump", $result['english']);
    }

    public function testAnalyzeJapaneseTextConditionalPatternAlias(): void
    {
        $result = analyzeJapaneseText('跳べるならば');
        $this->assertStringContainsString('if', $result['english']);
    }

    public function testAnalyzeJapaneseTextVoicebankAliasSkipsTokenGloss(): void
    {
        $result = analyzeJapaneseText('プロポーズ / 可不');
        $this->assertStringContainsString('Kafu', $result['english']);
        $this->assertStringNotContainsString('acceptable', $result['english']);
        $this->assertStringNotContainsString('un-', $result['english']);
    }

    public function testAnalyzeJapaneseTextDoesNotGlossAliasFragments(): void
    {
        $this->skipIfNoMecab();

        $megurine = analyzeJapaneseText('ハローセカイ / 初音ミク × 巡音ルカ');
        $this->assertStringContainsString('Megurine Luka', $megurine['english']);
        $this->assertStringNotContainsString('circumference', $megurine['english']);

        $kagamine = analyzeJapaneseText('ラヴィ 25時、ナイトコードで。 × 鏡音レン');
        $this->assertStringContainsString('Kagamine Len', $kagamine['english']);
        $this->assertStringNotContainsString('peak', $kagamine['english']);
        $this->assertStringNotContainsString('mirror', $kagamine['english']);
    }

    public function testGetRomajiNameKeepsAliasNamesWhole(): void
    {
        $this->skipIfNoMecab();

        $this->assertSame('megurine-ruka', getRomajiName('巡音ルカ'));
        $this->assertSame('kagamine-rin', getRomajiName('鏡音リン'));
    }

    public function testAnalyzeJapaneseTextSkipsProperNounHomophones(): void
    {
        $this->skipIfNoMecab();

        $result = analyzeJapaneseText('天馬');
        $this->assertStringNotContainsString('flying horse', $result['english']);
        $this->assertStringNotContainsString('pegasus', mb_strtolower($result['english']));

        $this->assertStringNotContainsString('phosphorus', analyzeJapaneseText('リン')['english']);
    }

    public function testAnalyzeJapaneseTextKeepsKatakanaLoanwords(): void
    {
        $this->skipIfNoMecab();

        $this->assertStringContainsString('nightmare', analyzeJapaneseText('ナイトメア')['english']);
        $this->assertStringContainsString('fusion', analyzeJapaneseText('フュージョン')['english']);
        $this->assertStringContainsString('pentatonic', analyzeJapaneseText('ペンタトニック')['english']);
    }

    public function testAnalyzeJapaneseTextAliasesVoicebanksAndProducers(): void
    {
        $cases = [
            '歌愛ユキ' => 'Kaai Yuki',
            '鳴花ミコト' => 'Meika Mikoto',
            'カイト' => 'KAITO',
            'かいりきベア' => 'Kairiki Bear',
            'まらしぃ' => 'Marasy',
            '八王子P' => 'Hachioji P',
            '稲葉曇' => 'Inaba Kumori',
            'すりぃ' => 'Surii',
        ];

        foreach ($cases as $input => $expected) {
            $this->assertStringContainsString($expected, analyzeJapaneseText($input)['english'], $input);
        }

        $this->assertStringNotContainsString('song love', analyzeJapaneseText('歌愛ユキ')['english']);
        $this->assertStringNotContainsString('kite', analyzeJapaneseText('カイト')['english']);
        $this->assertStringNotContainsString('dissociation', analyzeJapaneseText('かいりきベア')['english']);
    }

    public function testAnalyzeJapaneseTextOverridesMisleadingHomophones(): void
    {
        $this->skipIfNoMecab();

        $cases = [
            'エス' => 'S',
            'きみのためのうた' => 'you',
            '人マニア' => 'person',
            'リン' => 'Rin',
            '空に免じて' => 'sky',
            '心予報' => 'heart',
            '天才ロック' => 'rock',
            'ビート' => 'beat',
            '命に嫌われている' => 'to be',
            'potatoになっていく' => 'to go',
            'はぐ' => 'Hug',
            'そうだった' => 'so',
            'PV付' => 'with',
            'ゴミ分別団' => 'separation',
        ];
        foreach ($cases as $input => $expected) {
            $this->assertStringContainsString($expected, analyzeJapaneseText($input)['english'], $input);
        }

        $this->assertStringNotContainsString('jesus', mb_strtolower(analyzeJapaneseText('エス')['english']));
        $this->assertStringNotContainsString('egg yolk', analyzeJapaneseText('きみのためのうた')['english']);
        $this->assertStringNotContainsString('-ian', analyzeJapaneseText('人マニア')['english']);
        $this->assertStringNotContainsString('phosphorus', analyzeJapaneseText('リン')['english']);
    }

    public function testAnalyzeJapaneseTextSkipsGrammaticalFragments(): void
    {
        $this->skipIfNoMecab();

        $this->assertSame('', trim(analyzeJapaneseText('い')['english']));
        $this->assertSame('', trim(analyzeJapaneseText('的')['english']));
        $this->assertStringNotContainsString('good', analyzeJapaneseText('きみのためのうた')['english']);
        $this->assertStringNotContainsString('prefix for forming ordinal numbers', analyzeJapaneseText('第三審')['english']);
        $this->assertStringNotContainsString('indicates passive voice', analyzeJapaneseText('神のまにまに - れるりり')['english']);
    }

    public function testAnalyzeJapaneseTextProtectsCuratedNameFragments(): void
    {
        $this->skipIfNoMecab();

        $cases = [
            'かぐや姫' => 'Princess Kaguya',
            'とうほう' => 'Touhou',
            'デビルじゃないもん' => 'Devil',
            '星界' => 'Sekai',
            'アマネ' => 'Amane',
            'よみぃ' => 'Yomii',
            'いよわ' => 'Iyowa',
            'うそつきマカロン' => 'Liar',
            'どりーみんチュチュ' => "Dreamin' Chuchu",
            'かみさま' => 'God',
            'いーあるふぁんくらぶ' => 'Ii Aru Fan Club',
            'れるりり' => 'Rerulili',
            'ビートまりお' => 'Beat Mario',
            'わんだぴょい' => 'Wonder Pyo',
            'なんでもにうむ' => 'Nan Demo Niumu',
            'ずんだもん' => 'Zundamon',
            'かてらざわ' => 'Katerazawa',
            'あいしていたのに' => 'Even Though I Loved You',
        ];
        foreach ($cases as $input => $expected) {
            $this->assertStringContainsString($expected, analyzeJapaneseText($input)['english'], $input);
        }

        $this->assertStringNotContainsString('furniture', analyzeJapaneseText('かぐや姫')['english']);
        $this->assertStringNotContainsString('multi-floor building', analyzeJapaneseText('デビルじゃないもん')['english']);
        $this->assertStringNotContainsString('desk', analyzeJapaneseText('うそつきマカロン')['english']);
    }

    public function testAnalyzeJapaneseTextCuratedTitlesHaveEnglish(): void
    {
        $this->skipIfNoMecab();

        $cases = [
            'ワールドワイドワンダー' => 'Worldwide Wonder',
            'ハッピーシンセサイザ' => 'Happy Synthesizer',
            'モザイクロール' => 'Mosaic Roll',
            'ブループラネット' => 'Blue Planet',
            'シャルル' => 'Charles',
            'ハローセカイ' => 'Hello Sekai',
            'エス' => 'S',
            '승인해주세요 지니님! / 카사네 테토・오토마치 우나' => 'Please Approve, Genie-nim',
        ];
        foreach ($cases as $input => $expected) {
            $this->assertStringContainsString($expected, analyzeJapaneseText($input)['english'], $input);
        }

        $korean = analyzeJapaneseText('승인해주세요 지니님! / 카사네 테토・오토마치 우나')['english'];
        $this->assertStringContainsString('Kasane Teto', $korean);
        $this->assertStringContainsString('Otomachi Una', $korean);
    }

    public function testNormalizeSeparatorsKeepsLetterXInWords(): void
    {
        $this->assertSame('Maximum Xylophone', meel_japanese_normalize_separators('Maximum Xylophone'));
        $this->assertSame('Remix', meel_japanese_normalize_separators('Remix'));
    }

    public function testNormalizeSeparatorsTreatsStandaloneXAsSpace(): void
    {
        $out = meel_japanese_normalize_separators('A x B');
        $this->assertStringNotContainsString('x', $out);
        $this->assertSame('AB', str_replace(' ', '', $out));

        $sym = meel_japanese_normalize_separators('A × B');
        $this->assertStringNotContainsString('×', $sym);
        $this->assertSame('AB', str_replace(' ', '', $sym));
    }

    public function testGetRomajiNamePreservesLetterX(): void
    {
        $this->skipIfNoMecab();

        $this->assertSame('maximum-xylophone', getRomajiName('Maximum Xylophone'));
        $this->assertSame('remix', getRomajiName('Remix'));
    }

    public function testGetRomajiNameSplitsStandaloneX(): void
    {
        $this->skipIfNoMecab();

        $this->assertSame('a-b', getRomajiName('A x B'));
    }
}

/* reference build: MEeL-C5H5N5O [53d7e14930b8e361] */