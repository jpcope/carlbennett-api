<?php

namespace CarlBennett\API\Libraries;

use CarlBennett\API\Libraries\ColorNames;
use CarlBennett\API\Libraries\Common;
use CarlBennett\API\Libraries\Magic8Ball;
use CarlBennett\API\Libraries\WeatherReport;

class HipChat {

  public function handleWebhook(&$webhook_post_data) {
    $event          = $webhook_post_data->event;
    $room_id        = $webhook_post_data->item->room->id;
    $webhook_id     = $webhook_post_data->webhook_id;
    $room_api_token = "";

    foreach (Common::$settings->HipChat->webhook_room_map as $item) {
      if (/*UNCOMMENT*//*$item->room_id == $room_id && */$item->webhook_id == $webhook_id) {
        /*REMOVE*/$room_id = $item->room_id;
        $room_api_token = $item->room_api_token;
        break;
      }
    }

    $event_result = null;
    switch ($event) {
      case "room_message":
        $event_result = $this->handleWebhookMessage(
          $webhook_post_data,
          $room_id,
          $room_api_token,
          $webhook_post_data->item->message->message
        );
      break;
      default:
        $event_result = "invalid event";
    }

    if (extension_loaded("newrelic")) {
      newrelic_add_custom_parameter("event_name", $event);
      newrelic_add_custom_parameter("room_id", $room_id);
      newrelic_add_custom_parameter("webhook_id", $webhook_id);
      newrelic_add_custom_parameter("room_api_token", $room_api_token);
      newrelic_add_custom_parameter("event_result", $event_result);
    }

    return $event_result;
  }

  protected function handleWebhookMessage(&$webhook_post_data, $room_id, $room_api_token, $message) {
    $trimmed_message = trim($message); $matches = [];
    if (substr($trimmed_message, 0, 9) == "@weather ") {
      $location = substr($trimmed_message, 9);
      $weatherinfo = (new WeatherReport($location))->getAsMessage($webhook_post_data);
      $response = $this->sendMessage($room_id, $room_api_token, $weatherinfo->color, $weatherinfo->message, $weatherinfo->format, true);
      return [($response && $response->code == 204), $weatherinfo];
    }
    if (substr($message, 0, 6) == "@echo ") {
      $response = $this->sendMessage($room_id, $room_api_token, "gray", substr($message, 6), "text", true);
      return ($response && $response->code == 204);
    }
    $colors = new ColorNames();
    if ($color = $colors->colorMatchName($message)) {
      $response = $this->sendMessage($room_id, $room_api_token, "gray",
        "#" . $color->hex,
        "text", true);
      return ($response && $response->code == 204);
    }
    if (preg_match("/^.*is\s*it\s*lunch\s*time\s*yet.*$/", strtolower($message))) {
      $currentTime  = new \DateTime("now", new \DateTimeZone("America/Chicago"));
      $lunchtime    = new \DateTime("12PM", new \DateTimeZone("America/Chicago"));
      if ($currentTime->format("H") >= 12) {
        $lunchtime->add(new \DateInterval("P1D"));
      }
      $diffTime     = $currentTime->diff($lunchtime);
      $response     = $this->sendMessage($room_id, $room_api_token, "gray",
        "It'll be lunchtime in " . Common::intervalToString($diffTime, "a moment") . ".", "text", true);
      return ($response && $response->code == 204);
    }
    if (preg_match("/^.*ball,.*\?.*/", strtolower($message))) {
      $magic8ball = (new Magic8Ball())->getPrediction($message);
      $response = $this->sendMessage($room_id, $room_api_token, "gray", $magic8ball);
      return ($response && $response->code == 204);
    }
    if (preg_match("/^@google\s+(.*)$/", strtolower($message), $matches)) {
      $response = $this->sendMessage($room_id, $room_api_token, "gray",
        "https://www.google.com/search?q=" . urlencode($matches[1]), "text", true);
      return ($response && $response->code == 204);
    }
    if (strtolower($trimmed_message) == "ping") {
      $message = str_replace("i", "o", $message); $message = str_replace("I", "O", $message);
      $response = $this->sendMessage($room_id, $room_api_token, "gray", $message, "text", true);
      return ($response && $response->code == 204);
    }
    return false;
  }

  public function sendMessage($room_id, $room_api_token, $color, $message, $format = "html", $notify = true) {
    return Common::curlRequest(
      "https://api.hipchat.com/v2/room/" . urlencode($room_id) . "/notification?"
      . "auth_token=" . urlencode($room_api_token), json_encode([
        "color" => $color,
        "message" => $message,
        "message_format" => $format,
        "notify" => $notify,
      ]), "application/json;charset=utf-8"
    );
  }

}
