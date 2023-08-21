<?php

  define( "LOAD_START_TIME", microtime(true) );
  define( "DSN", "mysql:dbname=CoinDataTrade;host=127.0.0.1;charset=UTF8" );
  define( "DB_USER", "root" );
  define( "DB_PASS", "password" );

  ini_set('display_errors', "On");

  function minifier_output($buffer) {

    foreach(headers_list() as $line)
    {
      list($title, $data) = explode(": ", $line, 2);
      if (strtolower($title) == "content-type" && false === strpos($data, "text/html"))
        return $buffer;
    }

    $buffer = preg_replace_callback("/<pre.*?<\/pre>/is", function($matches) {
      return "_______here___prf__start" . base64_encode(urlencode($matches[0])) . "_______here___prf__end";
    }, $buffer);
    $buffer = preg_replace_callback("/<script.*?<\/script>/is", function($matches) {
      return "_______here___sct__start" . base64_encode(urlencode($matches[0])) . "_______here___sct__end";
    }, $buffer);
    $buffer = preg_replace_callback("/<textarea.*?<\/textarea>/is", function($matches) {
      return "_______here___txs__start" . base64_encode(urlencode($matches[0])) . "_______here___txs__end";
    }, $buffer);

    $buffer = preg_replace(array("/\>[^\S]+/s", "/[^\S]+\</s", "/(\s)+/s" ), array(">", "<", " "), $buffer);

    $buffer = preg_replace_callback("/_______here___prf__start.*?_______here___prf__end/is", function($matches) {
      return urldecode(base64_decode(substr(substr($matches[0], 24), 0, -22)));
    }, $buffer);
    $buffer = preg_replace_callback("/_______here___sct__start.*?_______here___sct__end/is", function($matches) {
      return urldecode(base64_decode(substr(substr($matches[0], 24), 0, -22)));
    }, $buffer);
    $buffer = preg_replace_callback("/_______here___txs__start.*?_______here___txs__end/is", function($matches) {
      return urldecode(base64_decode(substr(substr($matches[0], 24), 0, -22)));
    }, $buffer);

    if (substr($buffer, 0, 15) == "<!DOCTYPE html>")
      $buffer = substr($buffer, 15);

    return
      "<!DOCTYPE html><!--\n" .
        "\n" .
        "  DataCoinTrade.com / (c) 2023 ActiveTK.\n\n" .
        "  Server-Side Time: " . (microtime(true) - LOAD_START_TIME) . "s\n" .
        "  Cached Date: " . (new DateTime("now", new DateTimeZone("GMT")))->format("Y-m-d H:i:sP") . "\n" .
      "\n-->" . $buffer . "\n";

    return $buffer . "\n";

  }

  ob_start("minifier_output");

  function byte_format($size, $dec=-1, $separate=false){
    $units = array('B', 'KB', 'MB', 'GB', 'TB', 'PB');
    $digits = ($size == 0) ? 0 : floor( log($size, 1024) );
	
    $over = false;
    $max_digit = count($units) - 1;

    if($digits == 0)
      $num = $size;
    else if (!isset($units[$digits])) {
      $num = $size / (pow(1024, $max_digit));
      $over = true;
    } else
      $num = $size / (pow(1024, $digits));
	
    if($dec > -1 && $digits > 0) $num = sprintf("%.{$dec}f", $num);
    if($separate && $digits > 0) $num = number_format($num, $dec);
	
    return ($over) ? $num . $units[$max_digit] : $num . $units[$digits];
  }

  function Is_Paid_Success($BTCAddr, $Amount) {
    $Transactions = json_decode(file_get_contents("https://blockstream.info/api/address/" . $BTCAddr . "/txs"), true);
    foreach($Transactions as $TxInfo)
      if ($TxInfo["status"]["confirmed"] == true || $Amount < 0.0005)
        foreach($TxInfo["vout"] as $Output)
          if ($Output["scriptpubkey_address"] == $BTCAddr && $Amount * 10**8 <= $Output["value"] )
            return true;
    return false;
  }

  function AddLog($id, $text) {
    file_put_contents("/home/www-data/crypto/" . $id . "/log", date("Y/m/d H:i:s") . ": " . $text . "\n", FILE_APPEND);
  }

  function getDec($str) {
    if( preg_match('@\.(\d+)E\-(\d+)@',$str,$matches) ){
		$digit = strlen($matches[1])+$matches[2];
		$format  = "%.".$digit."f";
		$str     = sprintf($format,$str);
		return $str;
	}
	return $str;
  }

  if (isset($_GET["request"]) && !empty($_GET["request"]))
    define( "request_path", $_GET["request"] );
  else
    define( "request_path", "" );
  if ( empty(request_path) ) ;
  else if (request_path == "license") {
    require_once("/var/www/html/crypto/license.php");
    exit();
  }
  else if (request_path == "favicon.ico") {
    header("Content-Type: image/vnd.microsoft.icon");
    readfile("/var/www/html/crypto/img/bitcoin_logo.ico");
    exit();
  }
  else {

    $dbh = new PDO( DSN, DB_USER, DB_PASS );

    try {
      $stmt = $dbh->prepare('select * from CoinDataTrade where UniqueID = ? limit 1;');
      $stmt->execute( [request_path] );
      $row = $stmt->fetch( PDO::FETCH_ASSOC );
    } catch ( \Throwable $e ) { }
    if (isset($row["UniqueID"])) {

      $row["PVCount"] = $row["PVCount"] + 1;

      $rate = file_exists("/var/www/html/crypto/rate/" . date("Ymd")) ? file_get_contents("/var/www/html/crypto/rate/" . date("Ymd")) : file_get_contents("https://www.activetk.jp/tools/crypto?fromsystem=" . time()) * 1;
      file_put_contents("/var/www/html/crypto/rate/" . date("Ymd"), $rate);
      $value = $row["AmountFixedType"] == "t" ? round( htmlspecialchars($row["AmountYen"]) / $rate, 7 ) : htmlspecialchars($row["AmountBTC"]);

      if (isset($_GET["status"])) {
        header("Content-Type: application/json;charset=UTF-8");

        if (empty($_GET["status"]) || !is_string($_GET["status"])) {
          $NewPaymentID = substr(base_convert(sha1(md5(uniqid()).md5(microtime())), 16, 36), 0, 10);
          $NewCoin = preg_split("/\r\n|\r|\n/", file_get_contents("https://bitcoin.activetk.jp/gen"));
          $PubAddress = trim(explode(": ", $NewCoin[0])[1]);
          $PrivAddress = trim(explode(": ", $NewCoin[2])[1]);
          $headers = getallheaders();
          $UploaderIPaddr = isset($headers["CF-Connecting-IP"]) ? $headers["CF-Connecting-IP"] : "";
          $UploaderUA = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : "";

          try {
            $stmt = $dbh->prepare(
             "insert into PaymentsData(
                PaymentID, CreateTime, UserIPaddr, UserAgent, AmountBTC, PaymentAddrPub, PaymentAddrWIF, PaymentStatus, ProductID, DownloadCount
              )
              value(
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
              )"
            );
            $stmt->execute( [
              $NewPaymentID,
              time(),
              $UploaderIPaddr,
              $UploaderUA,
              $value,
              $PubAddress,
              $PrivAddress,
              "Waiting",
              $row["UniqueID"],
              "0"
            ] );
          } catch (\Throwable $e) { }
          AddLog($row["UniqueID"], "新規にデータを購入(支払い未確認、ID=" . $NewPaymentID . ")");

          echo json_encode(array("newsessid"=>$NewPaymentID, "address"=>$PubAddress, "amount"=>getDec( $value), "time"=>0));
        } else {
          try {
            $stmt = $dbh->prepare('select * from PaymentsData where PaymentID = ? limit 1;');
            $stmt->execute( [$_GET["status"]] );
            $pay = $stmt->fetch( PDO::FETCH_ASSOC );
          } catch ( \Throwable $e ) {
            die(json_encode(array("error"=>"決済処理中にエラーが発生しました(エラーコード1)。")));
          }
          if (!isset($pay["PaymentID"]))
            die(json_encode(array("error"=>"決済処理中にエラーが発生しました(エラーコード2)。")));
          else if ($pay["PaymentStatus"] == "Done" || Is_Paid_Success($pay["PaymentAddrPub"], $pay["AmountBTC"])) {

            try {
              if ($pay["PaymentStatus"] != "Done") {

                $stmt = $dbh->prepare("update PaymentsData set PaymentStatus = ? where PaymentID = ?;");
                $stmt->execute([
                  "Done",
                  $pay["PaymentID"]
                ]);
                AddLog($row["UniqueID"], "新規にデータを購入(支払の承認が完了しました、ID=" . $pay["PaymentID"] . "、金額=" . getDec( $pay["AmountBTC"]) . " BTC)");

              }
            } catch (\Throwable $e) { }

            exit(json_encode(array("done"=>$pay["PaymentID"], "address"=>$pay["PaymentAddrPub"], "amount"=>getDec( $pay["AmountBTC"]))));
          }
          exit(json_encode(array("address"=>$pay["PaymentAddrPub"], "amount"=>getDec( $pay["AmountBTC"]), "time"=>(time() - $pay["CreateTime"]*1))));
        }
        exit();
      }

      if (isset($_GET["download"]) && is_string($_GET["download"])) {

        header("X-Robots-Tag: noindex, nofollow");
        if (strpos($_SERVER['HTTP_USER_AGENT'], 'bot') !== false ||
          strpos($_SERVER['HTTP_USER_AGENT'], 'Bot') !== false
        ) {
          header("HTTP/1.1 404 NotFound");
          die();
        }

        try {
          $stmt = $dbh->prepare('select * from PaymentsData where PaymentID = ? limit 1;');
          $stmt->execute( [$_GET["download"]] );
          $pay = $stmt->fetch( PDO::FETCH_ASSOC );
        } catch ( \Throwable $e ) {
          die("ダウンロード処理中にエラーが発生しました(エラーコード3)。");
        }

        if (isset($pay["PaymentStatus"]) && $pay["PaymentStatus"] == "Done") {

          try {
            $stmt = $dbh->prepare("update PaymentsData set DownloadCount = ? where PaymentID = ?;");
            $stmt->execute([
              $pay["DownloadCount"] * 1 + 1,
              $pay["PaymentID"]
            ]);
            $stmt = $dbh->prepare("update CoinDataTrade set DLCount = ? where UniqueID = ?;");
              $stmt->execute([
              $row["DLCount"] * 1 + 1,
              $row["UniqueID"]
            ]);

          } catch (\Throwable $e) { }
          AddLog($row["UniqueID"], "データがダウンロードされました(ID=" . $pay["PaymentID"] . "、" . ($pay["DownloadCount"] * 1 + 1) . "回目)");

          $DataDir = "/home/www-data/crypto/" . $row["UniqueID"] . "/";
          header('Content-Type: application/octet-stream');
          header('X-Content-Type-Options: nosniff');
          header('Content-Length: ' . file_get_contents($DataDir . "filesize"));
          if (file_exists($DataDir . "filename"))
            header('Content-Disposition: attachment; filename="' . urlencode(basename(file_get_contents($DataDir . "filename"))) . '"');
          else
            header('Content-Disposition: attachment; filename="data.txt"');
          header('Connection: close');
          while (ob_get_level()) { ob_end_clean(); }
          echo gzinflate(file_get_contents($DataDir . "data"));

        }
        else
          die("ダウンロード処理中にエラーが発生しました(エラーコード4)。");

        exit();
      }

      try {
        $stmt = $dbh->prepare("update CoinDataTrade set PVCount = ? where UniqueID = ?;");
        $stmt->execute([
          $row["PVCount"],
          $row["UniqueID"]
        ]);
      } catch (\Throwable $e) { }

?>
<!DOCTYPE html>
<html lang="ja" itemscope="" itemtype="http://schema.org/WebPage" dir="ltr">
  <head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, minimum-scale=1, maximum-scale=1, user-scalable=no">
    <title><?=htmlspecialchars($row["Title"])?> - DataCoinTrade</title>
    <meta name="author" content="ActiveTK.">
    <meta name="robots" content="All">
    <meta name="description" content="Bitcoinでファイルやテキストを販売したり、購入することができます。">
    <meta name="copyright" content="Copyright &copy; 2023 ActiveTK. All rights reserved.">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://code.activetk.jp/ActiveTK.min.js"></script>
    <script src="https://code.activetk.jp/jquery-qrcode.min.js"></script>
    <script src="https://unpkg.com/typewriter-effect@2.18.2/dist/core.js"></script>
    <script>
      window.stInt = null;
      window.stat = localStorage.getItem("<?=$row["UniqueID"]?>") ? localStorage.getItem("<?=$row["UniqueID"]?>") : "";
      document.addEventListener("DOMContentLoaded", function() {
        _("main").style.display = "block";
        if (localStorage.getItem("<?=$row["UniqueID"]?>")) {
          window.stat = localStorage.getItem("<?=$row["UniqueID"]?>");
          _("paymentScript").style.display = "block";
          _("buy").style.display = "none";
          _("buyid").innerText = localStorage.getItem("<?=$row["UniqueID"]?>");
          updateStatus();
          window.stInt = setInterval('updateStatus()', 5000);
        }
        _("buy").onclick = function() {
          _("paymentScript").style.display = "block";
          _("buy").style.display = "none";
          updateStatus();
          window.stInt = setInterval('updateStatus()', 5000);
        }
        new Typewriter(_("dot"), {
          loop: true,
          delay: 75,
          autoStart: true,
          cursor: '|',
          strings: ['']
        });
      });
      function updateStatus() {
        fetch('/<?=$row["UniqueID"]?>?status=' + window.stat)
         .then((response) => response.json())
         .then((data) => ParseStatus(data));
      }
      function ParseStatus(data) {
        if (data["error"])
          alert("エラー: " + data["error"]);
        if (data["newsessid"]) {
          window.stat = data["newsessid"];
          localStorage.setItem("<?=$row["UniqueID"]?>", window.stat);
          _("buyid").innerText = data["newsessid"];
        }
        if (data["done"]) {
          _("buy").style.display = "none";
          _("download").style.display = "block";
          _("download").onclick = function() {
            window.location.href = "https://datacointrade.com/<?=$row["UniqueID"]?>?download=" + data["done"];
          }
          _("stat").innerHTML = "トランザクションが承認されました！";
          clearInterval(window.stInt);
        }
        if (data["address"]) {
          if (_("btcaddr").innerText != data["address"]) {
            _("btcaddr").innerText = data["address"];
            $('#qrcode').qrcode({width: 256, height: 256, text: 'bitcoin:' + data["address"]});
          }
        }
        if (data["amount"]) {
          _("amount").innerText = data["amount"] + " BTC";
        }
        if (data["time"]) {
          _("timer").innerText = data["time"];
        }
      }
    </script>
</head>
<body style="background-color:#CCFF99;">
  <div class="pt-4 sm:pt-10 lg:pt-12" style="display:inline;">
    <header class="mx-auto max-w-screen-2xl px-4 md:px-8">
      <div class="flex flex-col items-center justify-between gap-4 py-6 md:flex-row">
        <nav class="flex flex-wrap justify-center gap-x-4 gap-y-2 md:justify-start md:gap-6">
          <a href="/" class="transition duration-100 hover:text-indigo-500 active:text-indigo-600">ホーム</a>
          <a href="/license" class="transition duration-100 hover:text-indigo-500 active:text-indigo-600">利用規約</a>
          <a href="https://profile.activetk.jp/" class="transition duration-100 hover:text-indigo-500 active:text-indigo-600">開発者</a>
          <a href="https://www.activetk.jp/contact" class="transition duration-100 hover:text-indigo-500 active:text-indigo-600">お問い合わせ</a>
        </nav>
        <div class="flex gap-4">
          寄付: 1hackerMy1mcFbMu32ZuiQCvkqQMFnNvX
        </div>
      </div>
    </header>
  </div>
  <hr style="background-color:#000000;height:2px;">
  <br>
  <h1 class="text-3xl font-bold" align="center">
    <?=htmlspecialchars($row["Title"])?> - DataCoinTrade
  </h1>
  <br>
  <noscript><div align="center"><h1>このページを表示するには、JavaScriptを有効化して下さい。</h1></div></noscript>
  <div class="bg-white py-6 sm:py-8 lg:py-12" id="main" style="display:none;">
    <div align="center">
      <h1 class="text-3xl font-bold">【ファイルの情報】</h1>
      <br>
      <p>ファイル名: <?= (file_exists("/home/www-data/crypto/" . $row["UniqueID"] . "/filename") ? htmlspecialchars(file_get_contents("/home/www-data/crypto/" . $row["UniqueID"] . "/filename")) : "未指定") ?></p>
      <p>ファイルサイズ: <?= (file_exists("/home/www-data/crypto/" . $row["UniqueID"] . "/filesize") ? byte_format(htmlspecialchars(file_get_contents("/home/www-data/crypto/" . $row["UniqueID"] . "/filesize"))*1, 2, true) : "未指定") ?></p>
      <p>連絡先: <?=htmlspecialchars($row["ContactEmail"])?></p>
      <p>金額: <?=getDec( $value)?> BTC</p>
      <br>
      <input type="button" id="buy" class="inline-block rounded-lg bg-indigo-500 px-8 py-3 text-center text-sm font-semibold text-white outline-none ring-indigo-300 transition duration-100 hover:bg-indigo-600 focus-visible:ring active:bg-indigo-700 md:text-base" value="データを購入">
      <input type="button" id="download" style="display:none;" class="inline-block rounded-lg bg-indigo-500 px-8 py-3 text-center text-sm font-semibold text-white outline-none ring-indigo-300 transition duration-100 hover:bg-indigo-600 focus-visible:ring active:bg-indigo-700 md:text-base" value="データをダウンロード(購入済み)">

      <br><br>
      <div class="bg-white py-6 sm:py-8 lg:py-12" style="background-color:#e6e6fa;text:#363636;display:none;" id="paymentScript">
        <div class="mx-auto max-w-screen-xl px-4 md:px-8">
          <div class="grid gap-8 md:grid-cols-2 lg:gap-12">
            <div> 
              <div class="h-64 overflow-hidden rounded-lg bg-gray-100 shadow-lg md:h-auto" id="qrcode"></div>
            </div>
            <div class="md:pt-8">
              <p class="text-center font-bold md:text-left">以下のBitcoinアドレスまで、指定の金額を送金して下さい(決済ID: <span id="buyid"></span>)。</p>
              <p class="text-center font-bold md:text-left">ただし、金額にトランザクション手数料(Fee)は含まれず、送金金額に不足がある場合には正常に処理できません。</p>
              <br>
              <p class="text-center font-bold text-indigo-500 md:text-left" id="amount"></p>
              <h1 class="mb-4 text-center text-2xl font-bold text-gray-800 sm:text-3xl md:mb-6 md:text-left" id="btcaddr"></h1>
              <br>
              <p class="text-center font-bold md:text-left"><span id="stat">トランザクションを待機中..<span id="dot"></span> (<span id="timer">0</span>秒経過)</span></p>
           </div>
          </div>
        </div>
      </div>

        <div style="width:30%;">
          <hr><br>
          <h1 class="text-3xl font-bold">【公開コメント】</h1>
          <p>最新の100件のみ表示されます。</p>
          <?php

          $cf = "/home/www-data/crypto/" . $row["UniqueID"] . "/comments";
          if (file_exists($cf))
            $Comments = file( $cf , FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
          else
            $Comments = array();

          if ( isset( $_POST["add_title"] ) &&  isset( $_POST["add_data"] ) ) {

              if ( !is_string( $_POST["add_title"] ) || strlen( $_POST["add_title"] ) > 120 )
                echo "<div style='background-color:#404ff0;'><font color='#ff4500'><h1>書き込みに失敗しました: タイトルが不正です。</h1></font></div>";
              else if ( !is_string( $_POST["add_data"] ) || strlen( $_POST["add_data"] ) > 1080 )
                echo "<div style='background-color:#404ff0;'><font color='#ff4500'><h1>書き込みに失敗しました: 内容が不正です。</h1></font></div>";
              else
              {
                array_push(
                  $Comments,
                  json_encode(
                    array(
                      'Time' => time(),
                      'Count' => count($Comments) + 1,
                      'Title' => htmlspecialchars( $_POST["add_title"] ),
                      'InnerText' => htmlspecialchars( $_POST["add_data"] )
                    )
                  )
                );
                file_put_contents( $cf, implode("\r\n", $Comments) );
              }

          }

          if ( count( $Comments ) > 100 )
            $Comments = array_slice( $Comments, count( $Comments ) - 1000 );
          foreach( $Comments as $CommentJson ) {
            if ( empty( $CommentJson ) )
              continue;
            $Comment = json_decode( $CommentJson, true );

            ?>
            <div align="left" style="background-color:#cfcfef;color:#363636;">
               <span class="titleof"><?=$Comment["Count"]?>) <b><?=$Comment["Title"]?></b></span><br>
               <?=date( "Y/m/d H:i:s", $Comment["Time"] )?>
               <pre><?=$Comment["InnerText"]?></pre>
            </div><br>
            <?php
          }
          if ( count( $Comments ) === 0 )
            echo "<p>公開コメントはありません。</p>";

          ?>
          <hr><br>
          <h2 class="text-1xl font-bold">公開コメントを追加</h2>
          <form action="" enctype="multipart/form-data" method="post">
            <input type="text" class="w-full rounded border bg-gray-50 px-3 py-2 text-gray-800 outline-none ring-indigo-300 transition duration-100 focus:ring"  name="add_title" maxlength="120" placeholder="ここにタイトルを入力してください(120文字まで)" required>
            <br><br>
            <textarea name="add_data" maxlength="1080" placeholder="ここに内容を入力してください(1080文字まで)" class="w-full rounded border bg-gray-50 px-3 py-2 text-gray-800 outline-none ring-indigo-300 transition duration-100 focus:ring"  required></textarea>
            <br>
            <input type="submit" value="書き込む" style="width:73px;height:33px;background-color:#90ee90;">
          </form>
        </div>

    </div>
  </div>
  <div class="bg-white pt-4 sm:pt-10 lg:pt-12">
    <footer class="mx-auto max-w-screen-2xl px-4 md:px-8">
      <div class="py-8 text-center text-sm text-gray-400">(c) 2023 ActiveTK.</div>
    </footer>
  </div>
</body>
</html>
<?php
      exit();
    }

    header( "HTTP/1.1 404 NotFound" );
    die( "Not Found." );
  }

  function GetRand( int $len = 32 ) {

    $bytes = openssl_random_pseudo_bytes( $len / 2 );
    $str = bin2hex( $bytes );

    $usestr = '1234567890abcdefghijklmnopqrstuvwxyz';
    $str2 = substr( str_shuffle( $usestr ), 0, 12 );

    return substr( str_shuffle( $str . $str2 ) , 0, -12 );

  }

  if ( isset( $_POST["submitData"] ) ) {
    $Message = "";

    $Uniqid = GetRand(16);
    $DataDir = "/home/www-data/crypto/" . $Uniqid . "/";
    mkdir($DataDir, 0777);

    $Title = (isset($_POST["title"]) && is_string($_POST["title"])) ? $_POST["title"] : "";
    $BTCAddr = (isset($_POST["btcaddr"]) && is_string($_POST["btcaddr"])) ? $_POST["btcaddr"] : "";
    if (empty($BTCAddr))
      $Message = "Bitcoinアドレスを指定して下さい。";
    $Mail = (isset($_POST["mail"]) && is_string($_POST["mail"])) ? $_POST["mail"] : "";
    if (empty($Mail) || !preg_match('/^[a-z0-9._+^~-]+@[a-z0-9.-]+$/i', strtolower($Mail)))
      $Message = "正しいメールアドレスを指定して下さい。";
    $FixInBTC = isset( $_POST["fixPlaceinBTC"] ) ? "t": "f";
    $amountYen = (isset($_POST["amountYen"]) && is_string($_POST["amountYen"])) ? $_POST["amountYen"] : "";
    $amountBTC = (isset($_POST["amountBTC"]) && is_string($_POST["amountBTC"])) ? $_POST["amountBTC"] : "";
    if (empty($amountYen) || empty($amountBTC) || !is_numeric($amountYen) || !is_numeric($amountBTC))
      $Message = "金額を正しく指定して下さい。";

    if (!empty($Message)) { }
    else if (empty($_FILES["file"]["tmp_name"]) || !isset($_FILES['file']['error']) || !is_int($_FILES['file']['error'])) {
      $data = (isset($_POST["message"]) && is_string($_POST["message"])) ? $_POST["message"] : "";
      if (strlen($data) > 26000)
        $Message = "テキストの中身が26000文字を超過しています。ファイルとして共有して下さい。";
      if (empty($data))
        $Message = "ファイルを選択せず、かつテキストの中身を空にすることはできません。";
      if (empty($Message)) {
        file_put_contents($DataDir . "filesize", strlen($data));
        file_put_contents($DataDir . "data", gzdeflate($data, 9));
      }
    }
    else {
      if ($_FILES['file']['error'] != UPLOAD_ERR_OK)
        $Message = "ファイルアップロード時にエラーが発生しました。";// . outputVarDump($_FILES);
      if ($_FILES['file']['size'] > 1024 * 1024 * 100)
        $Message = "ファイルサイズが100MBを超えています。";
      $FileName = $_FILES['file']['name'];
      if (empty($Message)) {
        file_put_contents(
          $DataDir . "filename", $FileName
        );
        file_put_contents(
          $DataDir . "filesize", $_FILES['file']['size']
        );
        file_put_contents(
          $DataDir . "data",
          gzdeflate(
            file_get_contents($_FILES['file']['tmp_name'])
          )
        );
      }
    }

    $headers = getallheaders();
    $UploaderIPaddr = isset($headers["CF-Connecting-IP"]) ? $headers["CF-Connecting-IP"] : "";
    $UploaderUA = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : "";

    if (empty($Message)) {

      try {
        $dbh = new PDO( DSN, DB_USER, DB_PASS );
        $stmt = $dbh->prepare(
          "insert into CoinDataTrade(
             UniqueID, Title, FileName, AmountFixedType, AmountYen, AmountBTC, PaymentAddr, CreateTime, ContactEmail, UploaderIPaddr, UploaderUserAgent, LastUpdateTime, PVCount, DLCount, CommentsJsonfp
           )
           value(
             ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
           )"
        );
        $stmt->execute( [
          $Uniqid,
          $Title,
          "",
          $FixInBTC,
          $amountYen,
          $amountBTC,
          $BTCAddr,
          time(),
          $Mail,
          $UploaderIPaddr,
          $UploaderUA,
          time(),
          "0",
          "0",
          ""
        ] );
      } catch ( \Throwable $e ) {
        $Message = "SQLエラーが発生しました: " . $e->getMessage();
      }

      header( "Location: /?administrate=" . $Uniqid . "&" . "token=" . md5(md5(hash('sha256', $Uniqid . DB_PASS ))) );
      exit();

    }
  }

  if (isset($_GET["administrate"])) {
    header("X-Robots-Tag: noindex, nofollow");
    if (!isset($_GET["token"]) || !is_string($_GET["token"]) || md5(md5(hash('sha256', $_GET["administrate"] . DB_PASS))) != $_GET["token"]) {
      header("HTTP/1.1 403 ForHidden");
      exit("トークンが無効です。");
    }

    $dbh = new PDO( DSN, DB_USER, DB_PASS );
    try {
      $stmt = $dbh->prepare('select * from CoinDataTrade where UniqueID = ? limit 1;');
      $stmt->execute( [$_GET["administrate"]] );
      $row = $stmt->fetch( PDO::FETCH_ASSOC );
    } catch ( \Throwable $e ) {
      header("HTTP/1.1 500 InternalServerError");
      die( "SQLエラーが発生しました: " . $e->getMessage() );
    }

    $rate = file_exists("/var/www/html/crypto/rate/" . date("Ymd")) ? file_get_contents("/var/www/html/crypto/rate/" . date("Ymd")) : file_get_contents("https://www.activetk.jp/tools/crypto?fromsystem=" . time()) * 1;
    $minpay = round( 300 / $rate, 7 );

    $TotalEarn = 0;
    $List = "";
    try {
      $Notes = $dbh->query('select * from PaymentsData where PaymentStatus = "Done" and ProductID = "' . $row["UniqueID"] . '";');
      if ($Notes !== false) { }
      else die("SQLの実行中にエラーが発生しました。");
    } catch (\Throwable $e) { die("SQLエラーが発生しました。"); }

    foreach($Notes as $val) {
      $TotalEarn += $val["AmountBTC"];
      $List .= "<li>" . $val["AmountBTC"] . " BTC :　<a href='https://www.blockchain.com/explorer/addresses/btc/".$val["PaymentAddrPub"]."' target='_blank'>" . $val["PaymentAddrWIF"] . "</a></li>";
    }

  if (isset($_GET["send"])) {
    exit("収益の送信に成功しました。");
  }

?>
<!DOCTYPE html>
<html lang="ja" itemscope="" itemtype="http://schema.org/WebPage" dir="ltr">
  <head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, minimum-scale=1, maximum-scale=1, user-scalable=no">
    <title>管理画面 - DataCoinTrade - Bitcoinで簡単にデータを売買できるサイト</title>
    <meta name="author" content="ActiveTK.">
    <meta name="robots" content="noindex, nofollow">
    <meta name="description" content="Bitcoinでファイルやテキストを販売したり、購入することができます。">
    <meta name="copyright" content="Copyright &copy; 2023 ActiveTK. All rights reserved.">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>var BTC=<?=file_get_contents("https://www.activetk.jp/tools/crypto?fromsystem=2")?>;document.addEventListener("DOMContentLoaded",function(){document.getElementById("main").style.display="block",document.getElementById("amountBTC").value=(1*document.getElementById("amountYen").value/BTC).toFixed(7),document.getElementById("amountYen").onchange=function(){document.getElementById("amountBTC").value=(1*document.getElementById("amountYen").value/BTC).toFixed(7)},document.getElementById("amountBTC").onchange=function(){document.getElementById("amountYen").value=Math.round(BTC*(1*document.getElementById("amountBTC").value))}});</script>
</head>
<body style="background-color:#CCFF99;">
  <div class="pt-4 sm:pt-10 lg:pt-12" style="display:inline;">
    <header class="mx-auto max-w-screen-2xl px-4 md:px-8">
      <div class="flex flex-col items-center justify-between gap-4 py-6 md:flex-row">
        <nav class="flex flex-wrap justify-center gap-x-4 gap-y-2 md:justify-start md:gap-6">
          <a href="/" class="transition duration-100 hover:text-indigo-500 active:text-indigo-600">ホーム</a>
          <a href="/license" class="transition duration-100 hover:text-indigo-500 active:text-indigo-600">利用規約</a>
          <a href="https://profile.activetk.jp/" class="transition duration-100 hover:text-indigo-500 active:text-indigo-600">開発者</a>
          <a href="https://www.activetk.jp/contact" class="transition duration-100 hover:text-indigo-500 active:text-indigo-600">お問い合わせ</a>
        </nav>
        <div class="flex gap-4">
          寄付: 1hackerMy1mcFbMu32ZuiQCvkqQMFnNvX
        </div>
      </div>
    </header>
  </div>
  <hr style="background-color:#000000;height:2px;">
  <br>
  <h1 class="text-3xl font-bold" align="center">
    管理画面 - DataCoinTrade
  </h1>
  <br>
  <noscript><div align="center"><h1>このページを表示するには、JavaScriptを有効化して下さい。</h1></div></noscript>
  <div class="bg-white py-6 sm:py-8 lg:py-12" id="main" style="display:none;">
    <div align="center">
      <div style="text-align:left;width:80%;" align="left">
        <h1 class="text-3xl font-bold">ファイルの情報</h1>
        <br>
        <p>ファイルID: <?=$row["UniqueID"]?></p>
        <p>ダウンロードURL: https://datacointrade.com/<?=$row["UniqueID"]?></p>
        <p>ファイル名: <?= (file_exists("/home/www-data/crypto/" . $row["UniqueID"] . "/filename") ? htmlspecialchars(file_get_contents("/home/www-data/crypto/" . $row["UniqueID"] . "/filename")) : "未指定") ?></p>
        <p>ファイルサイズ: <?= (file_exists("/home/www-data/crypto/" . $row["UniqueID"] . "/filesize") ? htmlspecialchars(file_get_contents("/home/www-data/crypto/" . $row["UniqueID"] . "/filesize")) : "未指定") ?></p>
        <p>アップロード日時: <?=date("Y/m/d - M (D) H:i:s", $row["CreateTime"])?></p>
        <br>
        <p>データのタイトル: <?=htmlspecialchars($row["Title"])?></p>
        <p>金額: <?= ( $row["AmountFixedType"] == "t" ? htmlspecialchars($row["AmountYen"]) . "円" : htmlspecialchars($row["AmountBTC"]) . "BTC") ?></p>
        <p>支払い先のアドレス: <?=htmlspecialchars($row["PaymentAddr"])?></p>
        <p>連絡先: <?=htmlspecialchars($row["ContactEmail"])?></p>
        <p>ダウンロードページの閲覧数: <?=htmlspecialchars($row["PVCount"])?></p>
        <p>ダウンロード回数(購入回数とは一致しない場合があります): <?=htmlspecialchars($row["DLCount"])?></p>
        <br>
        <h1 class="text-3xl font-bold">購入/ダウンロードの履歴</h1>
        <br>
        <p><?= (file_exists("/home/www-data/crypto/" . $row["UniqueID"] . "/log") ? nl2br(htmlspecialchars(file_get_contents("/home/www-data/crypto/" . $row["UniqueID"] . "/log"))) : "ダウンロード履歴はありません。") ?></p>
        <br>
        <h1 class="text-3xl font-bold">収益の受け取り</h1>
        <br>
        <p class="text-2xl font-bold">現在の収益額: <?=$TotalEarn?> BTC</p>
        <p>トランザクション手数料を抑えるため、お支払い最低金額(<?=$minpay?>BTC=300円)を超えていると、収益は不定期に自動で支払われます。</p>
        <p>または、以下のプライベートキー(WIF形式)を用いてすぐに受け取ることもできます。</p>
        <details>
          <summary>プライベートキー(WIF形式)</summary>
          <?=$List?>
        </details>
      </div>
    </div>
  </div>
  <div class="bg-white pt-4 sm:pt-10 lg:pt-12">
    <footer class="mx-auto max-w-screen-2xl px-4 md:px-8">
      <div class="py-8 text-center text-sm text-gray-400">(c) 2023 ActiveTK.</div>
    </footer>
  </div>
</body>
</html>
<?php
    exit();
  }

?>
<!DOCTYPE html>
<html lang="ja" itemscope="" itemtype="http://schema.org/WebPage" dir="ltr">
  <head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, minimum-scale=1, maximum-scale=1, user-scalable=no">
    <title>DataCoinTrade - Bitcoinで簡単にデータを売買できるサイト</title>
    <meta name="author" content="ActiveTK.">
    <meta name="robots" content="All">
    <meta name="description" content="Bitcoinでファイルやテキストを販売したり、購入することができます。">
    <meta name="copyright" content="Copyright &copy; 2023 ActiveTK. All rights reserved.">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>var BTC=<?=file_get_contents("https://www.activetk.jp/tools/crypto?fromsystem=2")?>;document.addEventListener("DOMContentLoaded",function(){document.getElementById("main").style.display="block",document.getElementById("amountBTC").value=(1*document.getElementById("amountYen").value/BTC).toFixed(7),document.getElementById("amountYen").onchange=function(){document.getElementById("amountBTC").value=(1*document.getElementById("amountYen").value/BTC).toFixed(7)},document.getElementById("amountBTC").onchange=function(){document.getElementById("amountYen").value=Math.round(BTC*(1*document.getElementById("amountBTC").value))}});</script>
</head>
<body style="background-color:#CCFF99;">
  <div class="pt-4 sm:pt-10 lg:pt-12" style="display:inline;">
    <header class="mx-auto max-w-screen-2xl px-4 md:px-8">
      <div class="flex flex-col items-center justify-between gap-4 py-6 md:flex-row">
        <nav class="flex flex-wrap justify-center gap-x-4 gap-y-2 md:justify-start md:gap-6">
          <a href="/" class="transition duration-100 hover:text-indigo-500 active:text-indigo-600">ホーム</a>
          <a href="/license" class="transition duration-100 hover:text-indigo-500 active:text-indigo-600">利用規約</a>
          <a href="https://profile.activetk.jp/" class="transition duration-100 hover:text-indigo-500 active:text-indigo-600">開発者</a>
          <a href="https://www.activetk.jp/contact" class="transition duration-100 hover:text-indigo-500 active:text-indigo-600">お問い合わせ</a>
        </nav>
        <div class="flex gap-4">
          寄付: 1hackerMy1mcFbMu32ZuiQCvkqQMFnNvX
        </div>
      </div>
    </header>
  </div>
  <hr style="background-color:#000000;height:2px;">
  <br>
  <h1 class="text-3xl font-bold" align="center">
    DataCoinTrade - Bitcoin<img src="/crypto/img/bitcoin-btc-logo.png" style="width:26px;height:26px;display:inline-block;">で簡単にデータを売買できるサイト
  </h1>
  <br>
  <noscript><div align="center"><h1>このページを表示するには、JavaScriptを有効化して下さい。</h1></div></noscript>
  <?php if (isset($Message)) { ?>
    <div align="center" style="background-color:#404ff0;color:#ff4500;"><h1 class="text-2xl font-bold">エラー: <?=htmlspecialchars($Message)?></h1></div>
  <?php } ?>
  <div class="bg-white py-6 sm:py-8 lg:py-12" id="main" style="display:none;">
    <form action="" enctype="multipart/form-data" method="POST" class="mx-auto max-w-screen-2xl px-4 md:px-8">
      <div class="flex flex-col overflow-hidden rounded-lg bg-gray-200 sm:flex-row md:h-80">

        <div class="order-first h-48 w-full bg-gray-300 sm:order-none sm:h-auto sm:w-1/2 lg:w-1/2">
          <br>
          <p>ファイルをアップロードして共有(100MB以内):</p>
          <input name="file" type="file"><br><br>
          <p>または、以下のテキストを共有(26000文字以内):</p>
          <textarea name="message" class="w-full rounded border bg-gray-50 px-3 py-2 text-gray-800 outline-none ring-indigo-300 transition duration-100 focus:ring" style="height:40%;" placeholder="共有したいテキストの中身を入力して下さい。また、100MB以上のファイルを共有したい場合には、こちらに外部のファイルアップローダーのURLを掲載することもできます。"></textarea>
        </div>

        <div class="flex w-full flex-col p-4 sm:w-1/2 sm:p-8 lg:w-1/2">
          <div class="sm:col-span-2">
            <label for="title" class="mb-2 inline-block text-sm text-gray-800 sm:text-base">タイトル(データの概要、128文字以内): </label>
            <input name="title" class="w-full rounded border bg-gray-50 px-3 py-2 text-gray-800 outline-none ring-indigo-300 transition duration-100 focus:ring" maxlength="128" placeholder="Bitcoinの購入方法について解説した動画" required />
          </div>
          <br>
          <div class="sm:col-span-2">
            <label for="btcaddr" class="mb-2 inline-block text-sm text-gray-800 sm:text-base">支払い先のBitcoinアドレス(mainnet): </label>
            <input name="btcaddr" class="w-full rounded border bg-gray-50 px-3 py-2 text-gray-800 outline-none ring-indigo-300 transition duration-100 focus:ring" placeholder="1hackerMy1mcFbMu32ZuiQCvkqQMFnNvX" required />
          </div>
          <br>
          <p>販売金額 (<input type="checkbox" name="fixPlaceinBTC" checked>販売金額を日本円で固定する):</p>
          <div style="display:flex;">
            <p>
              <input name="amountYen" id="amountYen" type="number" class="border bg-gray-50 px-3 py-2 text-gray-800 outline-none ring-indigo-300 transition focus:ring" value="500" style="width:120px;height:40px;text-align:right;" required />円 = 
              <input name="amountBTC" id="amountBTC" type="number" class="border bg-gray-50 px-3 py-2 text-gray-800 outline-none ring-indigo-300 transition focus:ring" value="0" style="width:120px;height:40px;text-align:right;" step="0.000000000000000001" required />BTC
            </p>
          </div>
        </div>
      </div>
      <br>
      <div align="center">
        <div class="sm:col-span-2" style="width:30%;">
          <label for="mail" class="mb-2 inline-block text-sm text-gray-800 sm:text-base">連絡先のメールアドレス: </label>
          <input name="mail" type="email" class="w-full rounded border bg-gray-50 px-3 py-2 text-gray-800 outline-none ring-indigo-300 transition duration-100 focus:ring" placeholder="h@cker.jp" required />
        </div>
        <br>
        <input type="submit" name="submitData" class="inline-block rounded-lg bg-indigo-500 px-8 py-3 text-center text-sm font-semibold text-white outline-none ring-indigo-300 transition duration-100 hover:bg-indigo-600 focus-visible:ring active:bg-indigo-700 md:text-base" value="利用規約に同意して共有">
      </div>
    </div>
  </div>
  <div class="bg-white pt-4 sm:pt-10 lg:pt-12">
    <footer class="mx-auto max-w-screen-2xl px-4 md:px-8">
      <div class="py-8 text-center text-sm text-gray-400">(c) 2023 ActiveTK.</div>
    </footer>
  </div>
</body>
</html>
