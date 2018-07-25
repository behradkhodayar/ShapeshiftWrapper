<?php

/*
 * Created by KaraData.
 * file: ShapeShift.php
 * 
 * Wrapper class for shapeshift.io cryptocurrency swap service API.
 * 
 * Available coins by April 2018:
 * btc, ltc, ppc, drk, doge, nmc, ftc, blk, nxt, btcd, qrk, rdd, nbt, bts, bitusd, xcp, xmr
 *
 * In addition to REST API, Shapeshift also supports CORS, all you need to do to use it is, replacing "shapeshift.io"
 * with "cors.shapeshift.io" in your calls to API.
 *  
 * Year: 2018
 */

namespace AppBundle\Service;

class ShapeShift {

  private $credentials;
  private $endpointBase;
  private $availableCoins;
  private $coveredPairs;
  private $apiKey;
  public $fees;
  public $limits;
  private $nonce;
  private static $lastApiCallTime = 0;
  private $returnAddress;

  private function generateCoveredPairs(array $availableCoins) {
    $coveredPairs = array();
    for ($i = 0; $i < count($availableCoins); $i++) {
      for ($j = 0; $j < count($availableCoins); $j++) {
        if ($i == $j) {
          break;
        }
        $coveredPairs[] = $availableCoins[$i] . "_" . $availableCoins[$j];
      }
    }
    return $coveredPairs;
  }

  /**
   * Check if the provided pair is covered by ShapeShift
   * 
   * @param string $pair
   * @return mixed Trimmed-lowerCased pair if valid/null & false if no
   */
  private function isValidPair($pair) {
    $pair = trim(strtolower($pair));
    if ("" == $pair) {
      return $pair;
    } elseif (in_array($pair, $this->coveredPairs)) {
      return $pair;
    } else {
      return FALSE;
    }
  }

  /**
   * Internal Helper function. Check for target endpoint's subURI & confine generates absolute endpoint.
   * 
   * @param string $subURI
   * @param string $confine Depending on endpoint it plays role of pair, number, address & etc
   * @param Boolean $confineNullable whether or not you can ignore $confine
   * @return string
   * @throws \Exception
   */
  private function endpointBoilerplate($subURI, $confine = "", $confineNullable = TRUE) {
    $confine = $this->isValidPair($confine);
    $subURI = trim(strtolower($subURI));
    if (FALSE === $confine) {
      throw new \Exception("Please Provide valid input!");
      return;
    } elseif ("" == $confine) {
      if ($confineNullable) {
        return $this->endpointBase . $subURI . "/";
      } else {
        throw new \Exception("Please Provide valid input!");
      }
    } else {
      return $this->endpointBase . $subURI . "/" . $confine;
    }
  }

  public function __construct() {
    $this->credentials = [];
    // Affiliate PUBLIC KEY, for volume tracking, affiliate payments, split-shifts, etc
    $this->apiKey = "";
    $this->returnAddress = '';/** @todo specify this */
    $this->endpointBase = "https://shapeshift.io/";
    $this->availableCoins = ["btc", "ltc", "ppc", "drk", "doge", "nmc", "ftc", "blk", "nxt", "btcd", "qrk", "rdd",
        "nbt", "bts", "bitusd", "xcp", "xmr"];
    $this->coveredPairs = $this->generateCoveredPairs($this->availableCoins);
  }

  /* ---------------------------------------
   * ----------- GET Methods --------------
   * ------------------------------------- */

  /**
   * Call API endpoint returned by class methods when METHOD is GET. Internal function.
   * 
   * @param string $endpoint
   * @return type
   */
  private function getAPI($endpoint) {
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $endpoint);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);

    //TODO: Remove this line in production env
    curl_setopt($curl, CURLOPT_PROXY, "socks5://127.0.0.1:1080");

    $result = curl_exec($curl);
    curl_close($curl);
    return $result;
  }

  /**
   * API Doc URI: https://info.shapeshift.io/api#api-2
   * 
   * This is an estimate because the rate can occasionally change rapidly depending on the markets. The rate is also a
   * 'use-able' rate not a direct market rate. Meaning multiplying your input coin amount times the rate should give you
   * a close approximation of what will be sent out. This rate does not include the transaction (miner) fee taken off
   * every transaction.
   * 
   * Success Output:
   * {
   *  "pair" : "btc_ltc",
   *  "rate" : "70.1234"
   * }
   * 
   * @param string $pair
   * @return JSON Answer of error JSON
   */
  public function getRate($pair = "") {
    $endpoint = $this->endpointBoilerplate("rate", $pair);

    return $this->getAPI($endpoint);
  }

  /**
   * API Doc URI: https://info.shapeshift.io/api#api-3
   * 
   * Gets the current deposit limit set by Shapeshift. Amounts deposited over this limit will be sent to the return
   * address if one was entered, otherwise the user will need to contact ShapeShift support to retrieve their coins.
   * This is an estimate because a sudden market swing could move the limit. 
   * 
   * Success Output:
   * {
   *  "pair" : "btc_ltc",
   *  "limit" : "1.2345"
   * }
   * 
   * @param string $pair
   * @return JSON Answer of error JSON
   * @throws \Exception
   */
  public function getDepositLimit($pair = "") {
    $endpoint = $this->endpointBoilerplate("limit", $pair);

    return $this->getAPI($endpoint);
  }

  /**
   * API Doc URI: https://info.shapeshift.io/api#api-103
   * 
   * This gets the market info (pair, rate, limit, minimum limit, miner fee). The pair is not required and if not
   * specified will return an array of all market infos.
   * 
   * Success Output:
   * {
   *  "pair"     : "btc_ltc",
   *  "rate"     : 130.12345678,
   *  "limit"    : 1.2345,
   *  "min"      : 0.02621232,
   *  "minerFee" : 0.0001
   * }
   * 
   * @param string $pair
   * @return JSON Answer of error JSON
   */
  public function getMarketInfo($pair = "") {
    $endpoint = $this->endpointBoilerplate("marketinfo", $pair);

    return $this->getAPI($endpoint);
  }

  /**
   * API Doc URI: https://info.shapeshift.io/api#api-4
   * 
   * Get a list of the most recent transactions. If [max] is not specified this will return 5 transactions. Also, [max]
   * must be a number between 1 and 50 (inclusive).
   * 
   * Success Output:
   * [
   *    {
   *    curIn : [currency input],
   *    curOut: [currency output],
   *    amount: [amount],
   *    timestamp: [time stamp]     //in seconds
   *    },
   *    ...
   * ]
   *
   * @param integer $max optional maximum number of transactions to return.
   * @return JSON Answer of error JSON
   */
  public function getRecentTransactionList($max = "") {
    $endpoint = $this->endpointBoilerplate("recenttx", $max);

    return $this->getAPI($endpoint);
  }

  /**
   * API Doc URI: https://info.shapeshift.io/api#api-5
   * 
   * Get the status of most recent deposit to desired address.
   * 
   * Status: No Deposits Received
   * {
   *  status:"no_deposits",
   *  address:[address]           //matches address submitted
   * }
   * 
   * Status: Received (we see a new deposit but have not finished processing it)
   * 
   * {
   *  status:"received",
   *  address:[address]           //matches address submitted
   * }
   *  
   * Status: Complete
   * 
   * {
   *  status : "complete",
   *  address: [address],
   *  withdraw: [withdrawal address],
   *  incomingCoin: [amount deposited],
   *  incomingType: [coin type of deposit],
   *  outgoingCoin: [amount sent to withdrawal address],
   *  outgoingType: [coin type of withdrawal],
   *  transaction: [transaction id of coin sent to withdrawal address]
   * }
   * 
   * Status: Failed
   * {
   *  status : "failed",
   *  error: [Text describing failure]
   * }
   * 
   * Note: this can still get the normal style error returned. For example if request is made without an address.
   * 
   * @param string $address
   * @return JSON Answer or error JSON
   */
  public function getlastDepositToAddressStatus($address) {
    $endpoint = $this->endpointBoilerplate("txStat", $address, FALSE);

    return $this->getAPI($endpoint);
  }

  /**
   * API Doc URI: https://info.shapeshift.io/api#api-6
   * 
   * When a transaction is created with a fixed amount requested there is a 10 minute window for the deposit. After the
   *  10 minute window if the deposit has not been received the transaction expires and a new one must be created. This
   *  api call returns how many seconds are left before the transaction expires.
   * 
   * Success Output:
   * {
   *  status:"pending",
   *  seconds_remaining: 600
   * }
   * The status can be either "pending" or "expired".
   * If the status is expired then seconds_remaining will show 0.
   * 
   * @param string $address
   * @param boolean $isRipple is in charge of determining ripple, coz endpoint for ripple(XRP) differs from other coins
   * @return JSON Answer or error JSON
   * @throws Exception
   */
  public function getRemainingTimeOnFixedTX($address, $isRipple = FALSE) {
    if ($isRipple) {
      if ("" == trim($address)) {
        throw new Exception("Please Provide valid input!");
      }
      $address = urlencode(trim($address));
      $endpoint = $this->endpointBase . "timeremaining/" . $address . "?dt=destTagNUM";
    } else {
      $endpoint = $this->endpointBoilerplate("timeremaining", $address, FALSE);
    }

    return $this->getAPI($endpoint);
  }

  /**
   * API Doc URI: https://info.shapeshift.io/api#api-104
   * 
   * Get list of currently supported coins, at function call time.
   * 
   * Success Output:
   * {
   *  "SYMBOL1" :
   *   {
   *    name: ["Currency Formal Name"],
   *    symbol: <"SYMBOL1">,
   *    image: ["https://shapeshift.io/images/coins/coinName.png"],
   *    status: [available / unavailable]
   *   }
   *   (one listing per supported currency)
   * }
   * 
   * The status can be either "available" or "unavailable". Sometimes coins become temporarily unavailable during
   *  updates or unexpected service issues.
   * 
   * @return JSON Answer or error JSON
   */
  public function getCoinIconList() {
    $endpoint = $this->endpointBase . "getcoins";

    return $this->getAPI($endpoint);
  }

  /**
   * API Doc URI: https://info.shapeshift.io/api#api-105
   * 
   * Get list of transactions have been done using a specific PRIVATE API key in order to protect privacy of vendor's
   * account details.
   * 
   * Success Output:
   * [
   *  {
   *   inputTXID: [Transaction ID of the input coin going into shapeshift],
   *   inputAddress: [Address that the input coin was paid to for this shift],
   *   inputCurrency: [Currency type of the input coin],
   *   inputAmount: [Amount of input coin that was paid in on this shift],
   *   outputTXID: [Transaction ID of the output coin going out to user],
   *   outputAddress: [Address that the output coin was sent to for this shift],
   *   outputCurrency: [Currency type of the output coin],
   *   outputAmount: [Amount of output coin that was paid out on this shift],
   *   shiftRate: [The effective rate the user got on this shift.],
   *   status: [status of the shift] // can be "received", "complete", "returned" or "failed"
   *  }
   *  (one listing per transaction returned)
   * ]
   * 
   * @param type $apiKey
   * @return JSON Answer or error JSON
   */
  public function getTXListByPrivateAPIKey($apiKey) {
    $endpoint = $this->endpointBoilerplate("txbyapikey", $apiKey, FALSE);

    return $this->getAPI($endpoint);
  }

  /**
   * API Doc URI: https://info.shapeshift.io/api#api-106
   * 
   * Allows vendors to get a list of all transactions that have ever been sent to one of their addresses.
   * 
   *  
   * Success Output:
   *  [
   *   {
   *    inputTXID: [Transaction ID of the input coin going into shapeshift],
   *    inputAddress: [Address that the input coin was paid to for this shift],
   *    inputCurrency: [Currency type of the input coin],
   *    inputAmount: [Amount of input coin that was paid in on this shift],
   *    outputTXID: [Transaction ID of the output coin going out to user],
   *    outputAddress: [Address that the output coin was sent to for this shift],
   *    outputCurrency: [Currency type of the output coin],
   *    outputAmount: [Amount of output coin that was paid out on this shift],
   *    shiftRate: [The effective rate the user got on this shift.],
   *    status: [status of the shift] // can be "received", "complete", "returned" or "failed"
   *   }
   *   (one listing per transaction returned)
   * ]
   * 
   * @param string $outputAddress
   * @param string $apiKey
   * @param boolean $isRipple
   * @return JSON Answer or error JSON
   */
  public function getTXListByOutputAddress($outputAddress, $apiKey, $isRipple = FALSE) {
    if ($isRipple) {
      $outputAddress = urlencode($outputAddress);
      $params = $outputAddress . '/' . $apiKey . "?dt=destTagNUM";
      $endpoint = $this->endpointBoilerplate("txbyaddress", $params, FALSE);
    } else {
      $params = $outputAddress . '/' . $apiKey;
      $endpoint = $this->endpointBoilerplate("txbyaddress", $params, FALSE);
    }

    return $this->getAPI($endpoint);
  }

  /**
   * API Doc URI: https://info.shapeshift.io/api#api-107
   * 
   * Verify that their receiving address is a valid address according to a given wallet daemon.
   * 
   * Success Output:
   * {
   *  isValid: [TRUE/FALSE], // If FALSE, an error param will be present describing error message
   *  error: [(if isvalid is false, there will be an error message)]
   * }
   * 
   * @param string $address
   * @param string $coinSymbol
   * @return JSON Answer or error JSON
   */
  public function getAddressValidationResult($address, $coinSymbol) {
    $params = $address . '/' . $coinSymbol;
    $endpoint = $this->endpointBoilerplate("validateAddress", $params, FALSE);

    return $this->getAPI($endpoint);
  }

  /* ---------------------------------------
   * ----------- POST Methods --------------
   * ------------------------------------- */

  /**
   * Helper function for post api calls
   * 
   * @param string $endPoint
   * @param array $postParameters
   * @return mixed
   */
  public function callApi($endPoint, array $postParameters) {
//        $this->nonce = (string) time();
    $this->nonce = '3';
    //TODO: Enclose curl call in a try catch to implement exception handling
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $endPoint);
    curl_setopt($curl, CURLOPT_POST, 3);
    $queryString = "key=" . $this->credentials['apiKey']
            . "&signature=" . $this->signature()
            . "&nonce=" . $this->nonce;
//        die($this->nonce);
    foreach ($postParameters as $key => $value) {
      $queryString .= "&" . $key . "=" . $value;
    }
    curl_setopt($curl, CURLOPT_POSTFIELDS, $queryString);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

    //TODO: Remove this line in production env
    curl_setopt($curl, CURLOPT_PROXY, "socks5://127.0.0.1:1080");

    $this->rateAcquiescence($this->limits['rate']['interval']);
    $result = curl_exec($curl);
    self::$lastApiCallTime = $this->milliseconds();
    curl_close($curl);
    return $result;
  }

  /**
   * API Doc URI = https://info.shapeshift.io/api#api-7
   * 
   * Post required data(method args + apiKey[optional]) in JSON format to make normal transaction
   * 
   * example data: {"withdrawal":"AAAAAAAAAAAAA", "pair":"btc_ltc", returnAddress:"BBBBBBBBBBB"}
   *  
   * Success Output:
   *  {
   *    deposit: [Deposit Address (or memo field if input coin is BTS / BITUSD)],
   *    depositType: [Deposit Type (input coin symbol)],
   *    withdrawal: [Withdrawal Address], //-- will match address submitted in post
   *    withdrawalType: [Withdrawal Type (output coin symbol)],
   *    public: [NXT RS-Address pubkey (if input coin is NXT)],
   *    xrpDestTag : [xrpDestTag (if input coin is XRP)],
   *    apiPubKey: [public API attached to this shift, if one was given]
   *   }   
   * 
   * @param string $withdrawal the address for resulting coin to be sent to
   * @param string $pair what coins are being exchanged in the form [input coin]_[output coin]  ie btc_ltc
   * @param string $returnAddress (Optional) address to return deposit to if anything goes wrong with exchange
   * @param string $destTag (Optional) Destination tag that you want appended to a Ripple payment to you
   * @param string $rsAddress (Optional) For new NXT accounts to be funded, you supply this on NXT payment to you
   * @param boolean $needsApiKey (For endpoint apiKey is Optional) If it needs "apiKey" to be sent with request
   * 
   * @return JSON a json array like success output
   */
  public function postNormalTransaction($withdrawal, $pair, $returnAddress = Null, $destTag = Null, $rsAddress = Null, $needsApiKey = FALSE) {
    $endPoint = $this->endpointBase . "shift";
    $normalTransactionParameters = array(
        "withdrawal" => $withdrawal,
        "pair" => $pair
    );
//    $parametersCount = 2;
    if (isset($returnAddress) && !is_null($returnAddress)) {
      $normalTransactionParameters["returnAddress"] = $returnAddress;
//      $parametersCount++;
    }

    if (isset($destTag) && !is_null($destTag)) {
      $normalTransactionParameters["destTag"] = $destTag;
//      $parametersCount++;
    }

    if (isset($rsAddress) && !is_null($rsAddress)) {
      $normalTransactionParameters["rsAddress"] = $rsAddress;
//      $parametersCount++;
    }

    if ($needsApiKey) {
      $normalTransactionParameters["apiKey"] = $this->apiKey;
//      $parametersCount++;
    }

    $jsonParameters = json_encode($normalTransactionParameters);

    $ch = curl_init(); //Curl Handle
    curl_setopt($ch, CURLOPT_URL, $endPoint);
//    curl_setopt($ch, CURLOPT_POST, $parametersCount);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonParameters);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    //TODO: Remove this line in production env
    curl_setopt($curl, CURLOPT_PROXY, "socks5://127.0.0.1:1080");
    $result = curl_exec($ch);
    curl_close($ch);

    return $result;
  }

  /**
   * API Doc URI: https://info.shapeshift.io/api#api-8
   * 
   * This POST call requests a receipt for a transaction. The email address will be added to the conduit associated with
   * that transaction as well. (Soon it will also send receipts to subsequent transactions on that conduit) 
   * 
   * email    = the address for receipt email to be sent to
   * orderId    = the Order ID of your ShapeShift exchange
   * example data {"email":"mail@example.com", "orderId":"123ABC"}
   *  
   * Success Output:
   * {"email":
   *     {
   *         "status":"success",
   *         "message":"Email receipt sent"
   *     }
   * }
   * 
   * @param string $email email to send to Shapeshift
   * @param integer $orderId the regarding order's id
   * 
   * @return JSON An array same as success output above
   */
  public function postRequestEmailReceipt($email, $orderId) {
    $endPoint = $this->endpointBase . "mail";
    $requestEmailReceiptParameters = array(
        "email" => $email,
        "orderId" => $orderId
    );
    $jsonParameters = json_encode($requestEmailReceiptParameters);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $endPoint);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonParameters);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($ch, CURLOPT_PROXY, "socks5://127.0.0.1:1080");
    $result = curl_exec($ch);
    curl_close($ch);

    return $result;
  }

  /**
   * Fixed Amount Transaction / Quote Send Exact Price
   *
   * API Doc URI: https://info.shapeshift.io/api#api-9
   * 
   * This call allows you to request a fixed amount to be sent to the withdrawal address. You provide a withdrawal
   * address and the amount you want sent to it. We return the amount to deposit and the address to deposit to. This
   * allows you to use shapeshift as a payment mechanism. This call also allows you to request a quoted price on the
   * amount of a transaction without a withdrawal address. 
   * 
   * Success Output(for Send amount request):
   * {
   *   success:
   *   {
   *     pair: [pair],
   *     withdrawal: [Withdrawal Address], //-- will match address submitted in post
   *     withdrawalAmount: [Withdrawal Amount], // Amount of the output coin you will receive
   *     deposit: [Deposit Address (or memo field if input coin is BTS / BITUSD)],
   *     depositAmount: [Deposit Amount], // Exact amount of input coin to send in
   *     expiration: [timestamp when this will expire],
   *     quotedRate: [the exchange rate to be honored]
   *     apiPubKey: [public API attached to this shift, if one was given]
   *   }
   * }
   *      
   * Success Output(Quoted Price request)
   *  **This request will only return information about a quoted rate & will not generate the deposit address.**
   * {
   *   success:
   *     {
   *     pair: [pair],
   *     withdrawalAmount: [Withdrawal Amount], // Amount of the output coin you will receive
   *     depositAmount: [Deposit Amount], // Exact amount of input coin to send in
   *     expiration: [timestamp when this will expire],
   *     quotedRate: [the exchange rate to be honored]
   *     minerFee: [miner fee for this transaction]
   *     }
   * }
   * 
   * @param integer $amount         the amount to be sent to the withdrawal address
   * @param string $depositAmount   the amount to be sent to the deposit address
   * @param string $withdrawal      the address for coin to be sent to
   * @param string $pair            what coins are being exchanged in the form [input coin]_[output coin]  ie ltc_btc
   * @param string $returnAddress   (Optional) address to return deposit to if anything goes wrong with exchange
   * @param string $destTag         (Optional) Destination tag that you want appended to a Ripple payment to you
   * @param string $rsAddress       (Optional) For new NXT accounts to be funded, supply this on NXT payment to you
   * @param boolean $needsApiKey    (For endpoint apiKey is Optional) If it needs "apiKey" to be sent with request
   * @return JSON Result in JSON format or error JSON
   */
  public function postSendAmount($amount, $depositAmount, $withdrawal, $pair, $returnAddress = Null, $destTag = Null, $rsAddress = Null, $needsApiKey = FALSE) {
    $endpoint = $this->endpointBase . "sendamount";

    $SendAmountParameters = array(
        "amount" => $amount,
        "depositAmount" => $depositAmount,
        "withdrawal" => $withdrawal,
        "pair" => $pair
    );

    if (isset($returnAddress) && !is_null($returnAddress)) {
      $SendAmountParameters["returnAddress"] = $returnAddress;
    }

    if (isset($destTag) && !is_null($destTag)) {
      $SendAmountParameters["destTag"] = $destTag;
    }

    if (isset($rsAddress) && !is_null($rsAddress)) {
      $SendAmountParameters["rsAddress"] = $rsAddress;
    }

    if ($needsApiKey) {
      $SendAmountParameters["apiKey"] = $this->apiKey;
    }

    $jsonParameters = json_encode($SendAmountParameters);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $endPoint);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonParameters);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($ch, CURLOPT_PROXY, "socks5://127.0.0.1:1080");

    $result = curl_exec($ch);
    curl_close($ch);

    return $result;
  }

  /**
   * request for canceling a pending transaction by the deposit address.
   *
   * API Doc URI: https://info.shapeshift.io/api#api-108
   *
   * If there is fund sent to the deposit address, this pending transaction cannot be canceled.
   * 
   * Success Output:
   * { success : "Pending Transaction canceled" }
   * Error Output:
   * { error : {errorMessage} }
   * 
   * @param string $address The deposit address associated with the pending transaction
   * 
   * @return JSON Answer array like Success Output or Error JSON like Error Output 
   */
  public function postCancelPendingTransaction($address) {
    $endpoint = $this->endpointBase . "cancelpending";
    $cancelPendingParameters = array(
        "address" => $address
    );
    $jsonParameters = json_encode($cancelPendingParameters);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $endPoint);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonParameters);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    // @TODO: Remove following proxy for production
    curl_setopt($ch, CURLOPT_PROXY, "socks5://127.0.0.1:1080");
    $result = curl_exec($ch);
    curl_close($ch);

    return $result;
  }

}
