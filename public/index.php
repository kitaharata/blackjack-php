<?php
// --- セッション開始 ---
session_start();

// --- 定数定義 ---
define('DECK_SUITS', ['H', 'D', 'C', 'S']); // ハート, ダイヤ, クラブ, スペード
define('DECK_RANKS', ['A', '2', '3', '4', '5', '6', '7', '8', '9', '10', 'J', 'Q', 'K']);
define('BLACKJACK', 21);
define('DEALER_STAND_MIN', 17);

// --- ゲームロジック関数 ---

/**
 * 新しいデッキを作成する
 * @return array デッキの配列
 */
function createDeck(): array
{
    $deck = [];
    foreach (DECK_SUITS as $suit) {
        foreach (DECK_RANKS as $rank) {
            $deck[] = $suit . ' ' . $rank;
        }
    }
    return $deck;
}

/**
 * デッキをシャッフルする (参照渡し)
 * @param array $deck シャッフルするデッキ
 */
function shuffleDeck(array &$deck): void
{
    shuffle($deck);
}

/**
 * デッキからカードを1枚引く (参照渡し)
 * @param array $deck カードを引くデッキ
 * @return string|null 引いたカード。デッキが空ならnull
 */
function dealCard(array &$deck): ?string
{
    return array_pop($deck);
}

/**
 * 手札の合計値を計算する
 * @param array $hand 手札の配列
 * @return int 手札の合計値
 */
function calculateHandValue(array $hand): int
{
    $value = 0;
    $aceCount = 0;

    foreach ($hand as $card) {
        // カード文字列が不正な形式でないか基本的なチェック
        if (!is_string($card) || strpos($card, ' ') === false) {
            continue;
        }
        $parts = explode(' ', $card);
        // ランク部分が存在するか確認
        if (!isset($parts[1])) {
            continue;
        }
        $rank = $parts[1];

        if (is_numeric($rank)) {
            $value += (int)$rank;
        } elseif (in_array($rank, ['J', 'Q', 'K'])) {
            $value += 10;
        } elseif ($rank === 'A') {
            $aceCount++;
            $value += 11; // まず11として加算
        }
    }

    // エースの調整ロジック (バストしていてエースがあれば1として再計算)
    while ($value > BLACKJACK && $aceCount > 0) {
        $value -= 10; // 11として加算したものを1として扱うため10引く
        $aceCount--;
    }

    return $value;
}

/**
 * カードの表示用文字列を取得 (XSS対策済み)
 * @param string $card カード
 * @param bool $hidden ディーラーの隠しカードか？ デフォルトはfalse
 * @return string 表示用文字列
 */
function getCardDisplay(string $card, bool $hidden = false): string
{
    if ($hidden) {
        return '[Hidden]';
    }

    if (strpos($card, ' ') === false) {
        return '[Invalid Card]';
    }
    $parts = explode(' ', $card);
    if (!isset($parts[0]) || !isset($parts[1])) {
        return '[Invalid Card]';
    }
    $suit = $parts[0];
    $rank = $parts[1];

    $suitSymbols = [
        'H' => '♥',
        'D' => '♦',
        'C' => '♣',
        'S' => '♠'
    ];

    $suitSymbol = $suitSymbols[$suit] ?? '?'; // 不明なスートは '?'

    // htmlspecialcharsでXSS対策
    return htmlspecialchars($suitSymbol . $rank, ENT_QUOTES, 'UTF-8');
}

/**
 * ゲームの勝敗を判定する
 * @param int $playerValue プレイヤーの合計値
 * @param int $dealerValue ディーラーの合計値
 * @param bool $playerBusted プレイヤーがバストしたか
 * @param bool $dealerBusted ディーラーがバストしたか
 * @param bool $playerBlackjack プレイヤーが初期ブラックジャックか
 * @param bool $dealerBlackjack ディーラーが初期ブラックジャックか
 * @return string 'player_wins', 'dealer_wins', 'push' (引き分け)
 */
function determineWinner(
    int $playerValue,
    int $dealerValue,
    bool $playerBusted,
    bool $dealerBusted,
    bool $playerBlackjack,
    bool $dealerBlackjack
): string {
    if ($playerBlackjack && $dealerBlackjack) { // ダブルブラックジャック
        return 'push';
    }
    if ($playerBlackjack) {
        return 'player_wins';
    }
    if ($dealerBlackjack) {
        return 'dealer_wins';
    }
    if ($playerBusted) {
        return 'dealer_wins';
    }
    if ($dealerBusted) {
        return 'player_wins';
    }
    // スコア比較
    if ($playerValue > $dealerValue) {
        return 'player_wins';
    } elseif ($dealerValue > $playerValue) {
        return 'dealer_wins';
    } else {
        return 'push'; // スコアが同じ
    }
}

// --- POSTリクエスト処理 ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    // 'new_game'以外のアクションでセッションにゲームデータがなければリダイレクト
    if ($action !== 'new_game' && !isset($_SESSION['game_state'])) {
         header('Location: /');
         exit;
    }

    // アクションに応じた処理
    if ($action === 'new_game') {
        // 新しいゲームの開始
        $deck = createDeck();
        shuffleDeck($deck);

        $playerHand = [];
        $dealerHand = [];

        // 初期カード配布
        $playerHand[] = dealCard($deck);
        $dealerHand[] = dealCard($deck); // ディーラー1枚目 (隠す)
        $playerHand[] = dealCard($deck);
        $dealerHand[] = dealCard($deck); // ディーラー2枚目 (表向き)

        $playerValue = calculateHandValue($playerHand);
        // ディーラーの表示用スコアは表向きのカードのみ (インデックス1)
        $dealerValue = isset($dealerHand[1]) ? calculateHandValue([$dealerHand[1]]) : 0;
        // ディーラーの実際のスコア
        $dealerFullValue = calculateHandValue($dealerHand);
        $playerBusted = false;
        $dealerBusted = false;
        $gameOver = false;
        $playerBlackjack = ($playerValue === BLACKJACK && count($playerHand) === 2);
        $dealerBlackjack = ($dealerFullValue === BLACKJACK && count($dealerHand) === 2);
        $message = '';
        $dealerTurnStarted = false;

        // 初期ブラックジャック判定
        if ($playerBlackjack || $dealerBlackjack) {
            $gameOver = true;
            $dealerValue = $dealerFullValue; // ゲーム終了時はディーラーの全スコアを表示
            $dealerTurnStarted = true; // BJの場合はディーラーカードは公開

            $winner = determineWinner(
                $playerValue,
                $dealerFullValue,
                $playerBusted,
                $dealerBusted,
                $playerBlackjack,
                $dealerBlackjack
            );
            if ($winner === 'player_wins') {
                $message = "Blackjack! Player wins!";
            } elseif ($winner === 'dealer_wins') {
                $message = "Dealer Blackjack! Dealer wins!";
            } else {
                $message = "Push! Both have Blackjack!";
            }
        } else {
            $message = "Your turn. Hit or Stand?"; // プレイヤーのターン開始
        }

        // ゲーム状態をセッションに保存
        $_SESSION['game_state'] = [
            'deck'                => $deck,
            'playerHand'          => $playerHand,
            'dealerHand'          => $dealerHand,
            'playerValue'         => $playerValue,
            'dealerValue'         => $dealerValue,      // 表示用スコア
            'dealerFullValue'     => $dealerFullValue,  // 内部計算用フルスコア
            'playerBusted'        => $playerBusted,
            'dealerBusted'        => $dealerBusted,
            'message'             => $message,
            'gameOver'            => $gameOver,
            'player_blackjack'    => $playerBlackjack,  // 初期BJ判定結果
            'dealer_blackjack'    => $dealerBlackjack,  // 初期BJ判定結果
            'dealer_turn_started' => $dealerTurnStarted,
        ];

    } elseif ($action === 'hit') {
        // プレイヤーがヒット
        if (isset($_SESSION['game_state']) && !$_SESSION['game_state']['gameOver']) {
            $_SESSION['game_state']['playerHand'][] = dealCard($_SESSION['game_state']['deck']);
            $_SESSION['game_state']['playerValue'] = calculateHandValue($_SESSION['game_state']['playerHand']);

            // バスト判定
            if ($_SESSION['game_state']['playerValue'] > BLACKJACK) {
                $_SESSION['game_state']['playerBusted'] = true;
                $_SESSION['game_state']['gameOver'] = true;
                $_SESSION['game_state']['message'] = "Player busts! Dealer wins.";
                $_SESSION['game_state']['dealerValue'] = $_SESSION['game_state']['dealerFullValue']; // フルスコア表示へ
                $_SESSION['game_state']['dealer_turn_started'] = true; // ディーラーカード公開
            } else {
                $_SESSION['game_state']['message'] = "Your turn. Hit or Stand?"; // 再度アクションを促す
            }
        }

    } elseif ($action === 'stand') {
        // プレイヤーがスタンド
        if (isset($_SESSION['game_state']) && !$_SESSION['game_state']['gameOver']) {
            $_SESSION['game_state']['dealer_turn_started'] = true;
            $_SESSION['game_state']['gameOver'] = true; // プレイヤーのアクション終了
            $_SESSION['game_state']['dealerValue'] = $_SESSION['game_state']['dealerFullValue']; // ディーラーのフルスコアを表示

            // ディーラーのカード引きロジック
            while ($_SESSION['game_state']['dealerValue'] < DEALER_STAND_MIN) {
                $newCard = dealCard($_SESSION['game_state']['deck']);
                if ($newCard === null) { // デッキ切れ
                    break;
                }
                $_SESSION['game_state']['dealerHand'][] = $newCard;
                $_SESSION['game_state']['dealerValue'] = calculateHandValue($_SESSION['game_state']['dealerHand']);
            }
            $_SESSION['game_state']['dealerFullValue'] = $_SESSION['game_state']['dealerValue']; // 最終スコアを内部値にも反映

            // ディーラーバスト判定
            if ($_SESSION['game_state']['dealerValue'] > BLACKJACK) {
                $_SESSION['game_state']['dealerBusted'] = true;
            }

            // 最終的な勝敗判定
            $winner = determineWinner(
                $_SESSION['game_state']['playerValue'],
                $_SESSION['game_state']['dealerValue'],
                $_SESSION['game_state']['playerBusted'],
                $_SESSION['game_state']['dealerBusted'],
                $_SESSION['game_state']['player_blackjack'], // 初期BJフラグ
                $_SESSION['game_state']['dealer_blackjack']  // 初期BJフラグ
            );

            // 勝敗メッセージ設定
            if ($winner === 'player_wins') {
                $_SESSION['game_state']['message'] = "Player wins!";
            } elseif ($winner === 'dealer_wins') {
                $_SESSION['game_state']['message'] = "Dealer wins!";
            } else {
                $_SESSION['game_state']['message'] = "Push!";
            }
        }
    }

    // リダイレクト
    header('Location: /');
    exit;
}

// --- GETリクエスト処理 (表示準備) ---

// セッションからゲーム状態を読み込み (なければデフォルト値)
$gameState = $_SESSION['game_state'] ?? [
    'deck'                => [],
    'playerHand'          => [],
    'dealerHand'          => [],
    'playerValue'         => 0,
    'dealerValue'         => 0,
    'dealerFullValue'     => 0,
    'playerBusted'        => false,
    'dealerBusted'        => false,
    'message'             => 'Click "New Game" to start.',
    'gameOver'            => true,
    'player_blackjack'    => false,
    'dealer_blackjack'    => false,
    'dealer_turn_started' => false,
];

// 表示用変数の準備 (XSS対策含む)
$messageDisplay = htmlspecialchars($gameState['message'] ?? '', ENT_QUOTES, 'UTF-8');
$playerValueDisplay = htmlspecialchars($gameState['playerValue'] ?? 0, ENT_QUOTES, 'UTF-8');

$dealerValueToDisplay = 0;
if ($gameState['gameOver'] || $gameState['dealer_turn_started']) {
    $dealerValueToDisplay = $gameState['dealerFullValue'] ?? 0; // ゲーム終了/ディーラーターン開始後はフルスコア
} else {
    $dealerValueToDisplay = $gameState['dealerValue'] ?? 0; // 進行中は表示用スコア
}
$dealerValueDisplay = htmlspecialchars($dealerValueToDisplay, ENT_QUOTES, 'UTF-8');

// 手札表示用文字列の準備
$dealerHandDisplay = "(No cards yet)";
if (!empty($gameState['dealerHand'])) {
    $dealerCardsToDisplay = [];
    $hideFirstCard = !$gameState['gameOver'] && !$gameState['dealer_turn_started'] && !$gameState['dealer_blackjack'];
    if (isset($gameState['dealerHand'][0])) {
        $dealerCardsToDisplay[] = getCardDisplay($gameState['dealerHand'][0], $hideFirstCard); // 1枚目
    }
    $remainingDealerCards = array_slice($gameState['dealerHand'], 1); // 2枚目以降
    if (!empty($remainingDealerCards)) {
         $displayableRemainingCards = array_map('getCardDisplay', $remainingDealerCards);
         $dealerCardsToDisplay = array_merge($dealerCardsToDisplay, $displayableRemainingCards);
    }
    if (!empty($dealerCardsToDisplay)) {
        $dealerHandDisplay = implode(' ', $dealerCardsToDisplay); // スペース区切りで結合
    }
}

$playerHandDisplay = "(No cards yet)";
if (!empty($gameState['playerHand'])) {
    $playerCardsToDisplay = array_map('getCardDisplay', $gameState['playerHand']);
    $playerHandDisplay = implode(' ', $playerCardsToDisplay); // スペース区切りで結合
}

// --- HTML出力 ---
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Simple Blackjack</title>
<meta name="description" content="GitHub" />
<meta name="color-scheme" content="light dark" />
<meta name="twitter:card" content="summary" />
<meta property="og:title" content="Simple Blackjack" />
<meta property="og:description" content="GitHub" />
<meta property="og:site_name" content="Simple Blackjack | GitHub" />
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css" />
</head>
<body>
<main class="container">
<h1>Simple Blackjack</h1>
<article>
<header>Dealer's Hand</header>
<p><?php echo $dealerHandDisplay; ?></p>
<?php if ($gameState['dealerBusted']): ?>
<p><strong>BUSTED!</strong></p>
<?php endif; ?>
<footer>Value: <?php echo $dealerValueDisplay; ?></footer>
</article>
<article>
<header>Player's Hand</header>
<p><?php echo $playerHandDisplay; ?></p>
<?php if ($gameState['playerBusted']): ?>
<p><strong>BUSTED!</strong></p>
<?php endif; ?>
<footer>Value: <?php echo $playerValueDisplay; ?></footer>
</article>
<article>
<p><?php echo $messageDisplay; ?></p>
</article>
<form method="post">
<?php if ($gameState['gameOver']): ?>
<button type="submit" name="action" value="new_game">New Game</button>
<?php else: ?>
<div role="group">
<button type="submit" name="action" value="hit">Hit</button>
<button type="submit" name="action" value="stand">Stand</button>
</div>
<?php endif; ?>
</form>
</main>
</body>
</html>
