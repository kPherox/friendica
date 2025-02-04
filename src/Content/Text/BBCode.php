<?php
/**
 * @file src/Content/Text/BBCode.php
 */

namespace Friendica\Content\Text;

use DOMDocument;
use DOMXPath;
use Exception;
use Friendica\BaseObject;
use Friendica\Content\OEmbed;
use Friendica\Content\Smilies;
use Friendica\Core\Cache;
use Friendica\Core\Config;
use Friendica\Core\Hook;
use Friendica\Core\L10n;
use Friendica\Core\Logger;
use Friendica\Core\Protocol;
use Friendica\Core\Renderer;
use Friendica\Core\System;
use Friendica\Model\Contact;
use Friendica\Model\Event;
use Friendica\Model\Photo;
use Friendica\Network\Probe;
use Friendica\Object\Image;
use Friendica\Util\Map;
use Friendica\Util\Network;
use Friendica\Util\ParseUrl;
use Friendica\Util\Proxy as ProxyUtils;
use Friendica\Util\Strings;
use Friendica\Util\XML;

class BBCode extends BaseObject
{
	/**
	 * @brief Fetches attachment data that were generated the old way
	 *
	 * @param string $body Message body
	 * @return array
	 *                     'type' -> Message type ("link", "video", "photo")
	 *                     'text' -> Text before the shared message
	 *                     'after' -> Text after the shared message
	 *                     'image' -> Preview image of the message
	 *                     'url' -> Url to the attached message
	 *                     'title' -> Title of the attachment
	 *                     'description' -> Description of the attachment
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 */
	private static function getOldAttachmentData($body)
	{
		$post = [];

		// Simplify image codes
		$body = preg_replace("/\[img\=([0-9]*)x([0-9]*)\](.*?)\[\/img\]/ism", '[img]$3[/img]', $body);

		if (preg_match_all("(\[class=(.*?)\](.*?)\[\/class\])ism", $body, $attached, PREG_SET_ORDER)) {
			foreach ($attached as $data) {
				if (!in_array($data[1], ["type-link", "type-video", "type-photo"])) {
					continue;
				}

				$post["type"] = substr($data[1], 5);

				$pos = strpos($body, $data[0]);
				if ($pos > 0) {
					$post["text"] = trim(substr($body, 0, $pos));
					$post["after"] = trim(substr($body, $pos + strlen($data[0])));
				} else {
					$post["text"] = trim(str_replace($data[0], "", $body));
					$post["after"] = '';
				}

				$attacheddata = $data[2];

				if (preg_match("/\[img\](.*?)\[\/img\]/ism", $attacheddata, $matches)) {

					$picturedata = Image::getInfoFromURL($matches[1]);

					if ($picturedata) {
						if (($picturedata[0] >= 500) && ($picturedata[0] >= $picturedata[1])) {
							$post["image"] = $matches[1];
						} else {
							$post["preview"] = $matches[1];
						}
					}
				}

				if (preg_match("/\[bookmark\=(.*?)\](.*?)\[\/bookmark\]/ism", $attacheddata, $matches)) {
					$post["url"] = $matches[1];
					$post["title"] = $matches[2];
				}
				if (!empty($post["url"]) && (in_array($post["type"], ["link", "video"]))
					&& preg_match("/\[url\=(.*?)\](.*?)\[\/url\]/ism", $attacheddata, $matches)) {
					$post["url"] = $matches[1];
				}

				// Search for description
				if (preg_match("/\[quote\](.*?)\[\/quote\]/ism", $attacheddata, $matches)) {
					$post["description"] = $matches[1];
				}
			}
		}
		return $post;
	}

	/**
	 * @brief Fetches attachment data that were generated with the "attachment" element
	 *
	 * @param string $body Message body
	 * @return array
	 *                     'type' -> Message type ("link", "video", "photo")
	 *                     'text' -> Text before the shared message
	 *                     'after' -> Text after the shared message
	 *                     'image' -> Preview image of the message
	 *                     'url' -> Url to the attached message
	 *                     'title' -> Title of the attachment
	 *                     'description' -> Description of the attachment
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 */
	public static function getAttachmentData($body)
	{
		$data = [];

		if (!preg_match("/(.*)\[attachment(.*?)\](.*?)\[\/attachment\](.*)/ism", $body, $match)) {
			return self::getOldAttachmentData($body);
		}

		$attributes = $match[2];

		$data["text"] = trim($match[1]);

		$type = "";
		preg_match("/type='(.*?)'/ism", $attributes, $matches);
		if (!empty($matches[1])) {
			$type = strtolower($matches[1]);
		}

		preg_match('/type="(.*?)"/ism', $attributes, $matches);
		if (!empty($matches[1])) {
			$type = strtolower($matches[1]);
		}

		if ($type == "") {
			return [];
		}

		if (!in_array($type, ["link", "audio", "photo", "video"])) {
			return [];
		}

		if ($type != "") {
			$data["type"] = $type;
		}

		$url = "";
		preg_match("/url='(.*?)'/ism", $attributes, $matches);
		if (!empty($matches[1])) {
			$url = $matches[1];
		}

		preg_match('/url="(.*?)"/ism', $attributes, $matches);
		if (!empty($matches[1])) {
			$url = $matches[1];
		}

		if ($url != "") {
			$data["url"] = html_entity_decode($url, ENT_QUOTES, 'UTF-8');
		}

		$title = "";
		preg_match("/title='(.*?)'/ism", $attributes, $matches);
		if (!empty($matches[1])) {
			$title = $matches[1];
		}

		preg_match('/title="(.*?)"/ism', $attributes, $matches);
		if (!empty($matches[1])) {
			$title = $matches[1];
		}

		if ($title != "") {
			$title = self::convert(html_entity_decode($title, ENT_QUOTES, 'UTF-8'), false, true);
			$title = html_entity_decode($title, ENT_QUOTES, 'UTF-8');
			$title = str_replace(["[", "]"], ["&#91;", "&#93;"], $title);
			$data["title"] = $title;
		}

		$image = "";
		preg_match("/image='(.*?)'/ism", $attributes, $matches);
		if (!empty($matches[1])) {
			$image = $matches[1];
		}

		preg_match('/image="(.*?)"/ism', $attributes, $matches);
		if (!empty($matches[1])) {
			$image = $matches[1];
		}

		if ($image != "") {
			$data["image"] = html_entity_decode($image, ENT_QUOTES, 'UTF-8');
		}

		$preview = "";
		preg_match("/preview='(.*?)'/ism", $attributes, $matches);
		if (!empty($matches[1])) {
			$preview = $matches[1];
		}

		preg_match('/preview="(.*?)"/ism', $attributes, $matches);
		if (!empty($matches[1])) {
			$preview = $matches[1];
		}

		if ($preview != "") {
			$data["preview"] = html_entity_decode($preview, ENT_QUOTES, 'UTF-8');
		}

		$data["description"] = trim($match[3]);

		$data["after"] = trim($match[4]);

		return $data;
	}

	public static function getAttachedData($body, $item = [])
	{
		/*
		- text:
		- type: link, video, photo
		- title:
		- url:
		- image:
		- description:
		- (thumbnail)
		*/

		$has_title = !empty($item['title']);
		$plink = defaults($item, 'plink', '');
		$post = self::getAttachmentData($body);

		// Get all linked images with alternative image description
		if (preg_match_all("/\[img=([^\[\]]*)\]([^\[\]]*)\[\/img\]/Usi", $body, $pictures, PREG_SET_ORDER)) {
			foreach ($pictures as $picture) {
				if (Photo::isLocal($picture[1])) {
					$post['images'][] = ['url' => str_replace('-1.', '-0.', $picture[1]), 'description' => $picture[2]];
				}
			}
			if (!empty($post['images']) && !empty($post['images'][0]['description'])) {
				$post['image_description'] = $post['images'][0]['description'];
			}
		}

		if (preg_match_all("/\[img\]([^\[\]]*)\[\/img\]/Usi", $body, $pictures, PREG_SET_ORDER)) {
			foreach ($pictures as $picture) {
				if (Photo::isLocal($picture[1])) {
					$post['images'][] = ['url' => str_replace('-1.', '-0.', $picture[1]), 'description' => ''];
				}
			}
		}

		// if nothing is found, it maybe having an image.
		if (!isset($post["type"])) {
			// Simplify image codes
			$body = preg_replace("/\[img\=([0-9]*)x([0-9]*)\](.*?)\[\/img\]/ism", '[img]$3[/img]', $body);
			$body = preg_replace("/\[img\=(.*?)\](.*?)\[\/img\]/ism", '[img]$1[/img]', $body);
			$post["text"] = $body;

			if (preg_match_all("(\[url=(.*?)\]\s*\[img\](.*?)\[\/img\]\s*\[\/url\])ism", $body, $pictures, PREG_SET_ORDER)) {
				if ((count($pictures) == 1) && !$has_title) {
					if (!empty($item['object-type']) && ($item['object-type'] == ACTIVITY_OBJ_IMAGE)) {
						// Replace the preview picture with the real picture
						$url = str_replace('-1.', '-0.', $pictures[0][2]);
						$data = ['url' => $url, 'type' => 'photo'];
					} else {
						// Checking, if the link goes to a picture
						$data = ParseUrl::getSiteinfoCached($pictures[0][1], true);
					}

					// Workaround:
					// Sometimes photo posts to the own album are not detected at the start.
					// So we seem to cannot use the cache for these cases. That's strange.
					if (($data["type"] != "photo") && strstr($pictures[0][1], "/photos/")) {
						$data = ParseUrl::getSiteinfo($pictures[0][1], true);
					}

					if ($data["type"] == "photo") {
						$post["type"] = "photo";
						if (isset($data["images"][0])) {
							$post["image"] = $data["images"][0]["src"];
							$post["url"] = $data["url"];
						} else {
							$post["image"] = $data["url"];
						}

						$post["preview"] = $pictures[0][2];
						$post["text"] = trim(str_replace($pictures[0][0], "", $body));
					} else {
						$imgdata = Image::getInfoFromURL($pictures[0][1]);
						if ($imgdata && substr($imgdata["mime"], 0, 6) == "image/") {
							$post["type"] = "photo";
							$post["image"] = $pictures[0][1];
							$post["preview"] = $pictures[0][2];
							$post["text"] = trim(str_replace($pictures[0][0], "", $body));
						}
					}
				} elseif (count($pictures) > 0) {
					$post["type"] = "link";
					$post["url"] = $plink;
					$post["image"] = $pictures[0][2];
					$post["text"] = $body;

					foreach ($pictures as $picture) {
						$post["text"] = trim(str_replace($picture[0], "", $post["text"]));
					}
				}
			} elseif (preg_match_all("(\[img\](.*?)\[\/img\])ism", $body, $pictures, PREG_SET_ORDER)) {
				if ((count($pictures) == 1) && !$has_title) {
					$post["type"] = "photo";
					$post["image"] = $pictures[0][1];
					$post["text"] = str_replace($pictures[0][0], "", $body);
				} elseif (count($pictures) > 0) {
					$post["type"] = "link";
					$post["url"] = $plink;
					$post["image"] = $pictures[0][1];
					$post["text"] = $body;

					foreach ($pictures as $picture) {
						$post["text"] = trim(str_replace($picture[0], "", $post["text"]));
					}
				}
			}

			// Test for the external links
			preg_match_all("(\[url\](.*?)\[\/url\])ism", $post["text"], $links1, PREG_SET_ORDER);
			preg_match_all("(\[url\=(.*?)\].*?\[\/url\])ism", $post["text"], $links2, PREG_SET_ORDER);

			$links = array_merge($links1, $links2);

			// If there is only a single one, then use it.
			// This should cover link posts via API.
			if ((count($links) == 1) && !isset($post["preview"]) && !$has_title) {
				$post["type"] = "link";
				$post["url"] = $links[0][1];
			}

			// Now count the number of external media links
			preg_match_all("(\[vimeo\](.*?)\[\/vimeo\])ism", $post["text"], $links1, PREG_SET_ORDER);
			preg_match_all("(\[youtube\\](.*?)\[\/youtube\\])ism", $post["text"], $links2, PREG_SET_ORDER);
			preg_match_all("(\[video\\](.*?)\[\/video\\])ism", $post["text"], $links3, PREG_SET_ORDER);
			preg_match_all("(\[audio\\](.*?)\[\/audio\\])ism", $post["text"], $links4, PREG_SET_ORDER);

			// Add them to the other external links
			$links = array_merge($links, $links1, $links2, $links3, $links4);

			// Are there more than one?
			if (count($links) > 1) {
				// The post will be the type "text", which means a blog post
				unset($post["type"]);
				$post["url"] = $plink;
			}

			if (!isset($post["type"])) {
				$post["type"] = "text";
				$post["text"] = trim($body);
			}
		} elseif (isset($post["url"]) && ($post["type"] == "video")) {
			$data = ParseUrl::getSiteinfoCached($post["url"], true);

			if (isset($data["images"][0])) {
				$post["image"] = $data["images"][0]["src"];
			}
		}

		return $post;
	}

	/**
	 * @brief Converts a BBCode text into plaintext
	 *
	 * @param      $text
	 * @param bool $keep_urls Whether to keep URLs in the resulting plaintext
	 *
	 * @return string
	 */
	public static function toPlaintext($text, $keep_urls = true)
	{
		$naked_text = HTML::toPlaintext(BBCode::convert($text, false, 0, true), 0, !$keep_urls);

		return $naked_text;
	}

	private static function proxyUrl($image, $simplehtml = false)
	{
		// Only send proxied pictures to API and for internal display
		if (in_array($simplehtml, [false, 2])) {
			return ProxyUtils::proxifyUrl($image);
		} else {
			return $image;
		}
	}

	public static function scaleExternalImages($srctext, $include_link = true, $scale_replace = false)
	{
		// Suppress "view full size"
		if (intval(Config::get('system', 'no_view_full_size'))) {
			$include_link = false;
		}

		// Picture addresses can contain special characters
		$s = htmlspecialchars_decode($srctext);

		$matches = null;
		$c = preg_match_all('/\[img.*?\](.*?)\[\/img\]/ism', $s, $matches, PREG_SET_ORDER);
		if ($c) {
			foreach ($matches as $mtch) {
				Logger::log('scale_external_image: ' . $mtch[1]);

				$hostname = str_replace('www.', '', substr(System::baseUrl(), strpos(System::baseUrl(), '://') + 3));
				if (stristr($mtch[1], $hostname)) {
					continue;
				}

				// $scale_replace, if passed, is an array of two elements. The
				// first is the name of the full-size image. The second is the
				// name of a remote, scaled-down version of the full size image.
				// This allows Friendica to display the smaller remote image if
				// one exists, while still linking to the full-size image
				if ($scale_replace) {
					$scaled = str_replace($scale_replace[0], $scale_replace[1], $mtch[1]);
				} else {
					$scaled = $mtch[1];
				}
				$i = Network::fetchUrl($scaled);
				if (!$i) {
					return $srctext;
				}

				// guess mimetype from headers or filename
				$type = Image::guessType($mtch[1], true);

				if ($i) {
					$Image = new Image($i, $type);
					if ($Image->isValid()) {
						$orig_width = $Image->getWidth();
						$orig_height = $Image->getHeight();

						if ($orig_width > 640 || $orig_height > 640) {
							$Image->scaleDown(640);
							$new_width = $Image->getWidth();
							$new_height = $Image->getHeight();
							Logger::log('scale_external_images: ' . $orig_width . '->' . $new_width . 'w ' . $orig_height . '->' . $new_height . 'h' . ' match: ' . $mtch[0], Logger::DEBUG);
							$s = str_replace(
								$mtch[0],
								'[img=' . $new_width . 'x' . $new_height. ']' . $scaled . '[/img]'
								. "\n" . (($include_link)
									? '[url=' . $mtch[1] . ']' . L10n::t('view full size') . '[/url]' . "\n"
									: ''),
								$s
							);
							Logger::log('scale_external_images: new string: ' . $s, Logger::DEBUG);
						}
					}
				}
			}
		}

		// replace the special char encoding
		$s = htmlspecialchars($s, ENT_NOQUOTES, 'UTF-8');
		return $s;
	}

	/**
	 * The purpose of this function is to apply system message length limits to
	 * imported messages without including any embedded photos in the length
	 *
	 * @brief Truncates imported message body string length to max_import_size
	 * @param string $body
	 * @return string
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 */
	public static function limitBodySize($body)
	{
		$maxlen = Config::get('config', 'max_import_size', 0);

		// If the length of the body, including the embedded images, is smaller
		// than the maximum, then don't waste time looking for the images
		if ($maxlen && (strlen($body) > $maxlen)) {

			Logger::log('the total body length exceeds the limit', Logger::DEBUG);

			$orig_body = $body;
			$new_body = '';
			$textlen = 0;

			$img_start = strpos($orig_body, '[img');
			$img_st_close = ($img_start !== false ? strpos(substr($orig_body, $img_start), ']') : false);
			$img_end = ($img_start !== false ? strpos(substr($orig_body, $img_start), '[/img]') : false);
			while (($img_st_close !== false) && ($img_end !== false)) {

				$img_st_close++; // make it point to AFTER the closing bracket
				$img_end += $img_start;
				$img_end += strlen('[/img]');

				if (!strcmp(substr($orig_body, $img_start + $img_st_close, 5), 'data:')) {
					// This is an embedded image

					if (($textlen + $img_start) > $maxlen) {
						if ($textlen < $maxlen) {
							Logger::log('the limit happens before an embedded image', Logger::DEBUG);
							$new_body = $new_body . substr($orig_body, 0, $maxlen - $textlen);
							$textlen = $maxlen;
						}
					} else {
						$new_body = $new_body . substr($orig_body, 0, $img_start);
						$textlen += $img_start;
					}

					$new_body = $new_body . substr($orig_body, $img_start, $img_end - $img_start);
				} else {

					if (($textlen + $img_end) > $maxlen) {
						if ($textlen < $maxlen) {
							Logger::log('the limit happens before the end of a non-embedded image', Logger::DEBUG);
							$new_body = $new_body . substr($orig_body, 0, $maxlen - $textlen);
							$textlen = $maxlen;
						}
					} else {
						$new_body = $new_body . substr($orig_body, 0, $img_end);
						$textlen += $img_end;
					}
				}
				$orig_body = substr($orig_body, $img_end);

				if ($orig_body === false) {
					// in case the body ends on a closing image tag
					$orig_body = '';
				}

				$img_start = strpos($orig_body, '[img');
				$img_st_close = ($img_start !== false ? strpos(substr($orig_body, $img_start), ']') : false);
				$img_end = ($img_start !== false ? strpos(substr($orig_body, $img_start), '[/img]') : false);
			}

			if (($textlen + strlen($orig_body)) > $maxlen) {
				if ($textlen < $maxlen) {
					Logger::log('the limit happens after the end of the last image', Logger::DEBUG);
					$new_body = $new_body . substr($orig_body, 0, $maxlen - $textlen);
				}
			} else {
				Logger::log('the text size with embedded images extracted did not violate the limit', Logger::DEBUG);
				$new_body = $new_body . $orig_body;
			}

			return $new_body;
		} else {
			return $body;
		}
	}

	/**
	 * Processes [attachment] tags
	 *
	 * Note: Can produce a [bookmark] tag in the returned string
	 *
	 * @brief Processes [attachment] tags
	 * @param string   $return
	 * @param bool|int $simplehtml
	 * @param bool     $tryoembed
	 * @return string
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 */
	private static function convertAttachment($return, $simplehtml = false, $tryoembed = true)
	{
		$data = self::getAttachmentData($return);
		if (empty($data) || empty($data["url"])) {
			return $return;
		}

		if (isset($data["title"])) {
			$data["title"] = strip_tags($data["title"]);
			$data["title"] = str_replace(["http://", "https://"], "", $data["title"]);
		} else {
			$data["title"] = null;
		}

		if (((strpos($data["text"], "[img=") !== false) || (strpos($data["text"], "[img]") !== false) || Config::get('system', 'always_show_preview')) && !empty($data["image"])) {
			$data["preview"] = $data["image"];
			$data["image"] = "";
		}

		$return = '';
		if (in_array($simplehtml, [7, 9])) {
			$return = self::convertUrlForOStatus($data["url"]);
		} elseif (($simplehtml != 4) && ($simplehtml != 0)) {
			$return = sprintf('<a href="%s" target="_blank">%s</a><br>', $data["url"], $data["title"]);
		} else {
			try {
				if ($tryoembed && OEmbed::isAllowedURL($data['url'])) {
					$return = OEmbed::getHTML($data['url'], $data['title']);
				} else {
					throw new Exception('OEmbed is disabled for this attachment.');
				}
			} catch (Exception $e) {
				$data["title"] = defaults($data, 'title', $data['url']);

				if ($simplehtml != 4) {
					$return = sprintf('<div class="type-%s">', $data["type"]);
				}

				if (!empty($data['title']) && !empty($data['url'])) {
					if (!empty($data["image"]) && empty($data["text"]) && ($data["type"] == "photo")) {
						$return .= sprintf('<a href="%s" target="_blank"><img src="%s" alt="" title="%s" class="attachment-image" /></a>', $data["url"], self::proxyUrl($data["image"], $simplehtml), $data["title"]);
					} else {
						if (!empty($data["image"])) {
							$return .= sprintf('<a href="%s" target="_blank"><img src="%s" alt="" title="%s" class="attachment-image" /></a><br />', $data["url"], self::proxyUrl($data["image"], $simplehtml), $data["title"]);
						} elseif (!empty($data["preview"])) {
							$return .= sprintf('<a href="%s" target="_blank"><img src="%s" alt="" title="%s" class="attachment-preview" /></a><br />', $data["url"], self::proxyUrl($data["preview"], $simplehtml), $data["title"]);
						}
						$return .= sprintf('<h4><a href="%s">%s</a></h4>', $data['url'], $data['title']);
					}
				}

				if (!empty($data["description"]) && $data["description"] != $data["title"]) {
					// Sanitize the HTML by converting it to BBCode
					$bbcode = HTML::toBBCode($data["description"]);
					$return .= sprintf('<blockquote>%s</blockquote>', trim(self::convert($bbcode)));
				}

				if (!empty($data['url'])) {
					$return .= sprintf('<sup><a href="%s">%s</a></sup>', $data['url'], parse_url($data['url'], PHP_URL_HOST));
				}

				if ($simplehtml != 4) {
					$return .= '</div>';
				}
			}
		}

		return trim(defaults($data, 'text', '') . ' ' . $return . ' ' . defaults($data, 'after', ''));
	}

	public static function removeShareInformation($Text, $plaintext = false, $nolink = false)
	{
		$data = self::getAttachmentData($Text);

		if (!$data) {
			return $Text;
		} elseif ($nolink) {
			return $data["text"] . defaults($data, 'after', '');
		}

		$title = htmlentities(defaults($data, 'title', ''), ENT_QUOTES, 'UTF-8', false);
		$text = htmlentities($data["text"], ENT_QUOTES, 'UTF-8', false);
		if ($plaintext || (($title != "") && strstr($text, $title))) {
			$data["title"] = $data["url"];
		} elseif (($text != "") && strstr($title, $text)) {
			$data["text"] = $data["title"];
			$data["title"] = $data["url"];
		}

		if (empty($data["text"]) && !empty($data["title"]) && empty($data["url"])) {
			return $data["title"] . $data["after"];
		}

		// If the link already is included in the post, don't add it again
		if (!empty($data["url"]) && strpos($data["text"], $data["url"])) {
			return $data["text"] . $data["after"];
		}

		$text = $data["text"];

		if (!empty($data["url"]) && !empty($data["title"])) {
			$text .= "\n[url=" . $data["url"] . "]" . $data["title"] . "[/url]";
		} elseif (!empty($data["url"])) {
			$text .= "\n[url]" . $data["url"] . "[/url]";
		}

		return $text . "\n" . $data["after"];
	}

	/**
	 * Converts [url] BBCodes in a format that looks fine on Mastodon. (callback function)
	 *
	 * @brief Converts [url] BBCodes in a format that looks fine on Mastodon. (callback function)
	 * @param array $match Array with the matching values
	 * @return string reformatted link including HTML codes
	 */
	private static function convertUrlForOStatusCallback($match)
	{
		$url = $match[1];

		if (isset($match[2]) && ($match[1] != $match[2])) {
			return $match[0];
		}

		$parts = parse_url($url);
		if (!isset($parts['scheme'])) {
			return $match[0];
		}

		return self::convertUrlForOStatus($url);
	}

	/**
	 * @brief Converts [url] BBCodes in a format that looks fine on OStatus systems.
	 * @param string $url URL that is about to be reformatted
	 * @return string reformatted link including HTML codes
	 */
	private static function convertUrlForOStatus($url)
	{
		$parts = parse_url($url);
		$scheme = $parts['scheme'] . '://';
		$styled_url = str_replace($scheme, '', $url);

		if (strlen($styled_url) > 30) {
			$styled_url = substr($styled_url, 0, 30) . "…";
		}

		$html = '<a href="%s" target="_blank">%s</a>';

		return sprintf($html, $url, $styled_url);
	}

	/*
	 * [noparse][i]italic[/i][/noparse] turns into
	 * [noparse][ i ]italic[ /i ][/noparse],
	 * to hide them from parser.
	 */
	private static function escapeNoparseCallback($match)
	{
		$whole_match = $match[0];
		$captured = $match[1];
		$spacefied = preg_replace("/\[(.*?)\]/", "[ $1 ]", $captured);
		$new_str = str_replace($captured, $spacefied, $whole_match);
		return $new_str;
	}

	/*
	 * The previously spacefied [noparse][ i ]italic[ /i ][/noparse],
	 * now turns back and the [noparse] tags are trimed
	 * returning [i]italic[/i]
	 */
	private static function unescapeNoparseCallback($match)
	{
		$captured = $match[1];
		$unspacefied = preg_replace("/\[ (.*?)\ ]/", "[$1]", $captured);
		return $unspacefied;
	}

	/**
	 * Returns the bracket character positions of a set of opening and closing BBCode tags, optionally skipping first
	 * occurrences
	 *
	 * @param string $text        Text to search
	 * @param string $name        Tag name
	 * @param int    $occurrences Number of first occurrences to skip
	 * @return boolean|array
	 */
	public static function getTagPosition($text, $name, $occurrences = 0)
	{
		if ($occurrences < 0) {
			$occurrences = 0;
		}

		$start_open = -1;
		for ($i = 0; $i <= $occurrences; $i++) {
			if ($start_open !== false) {
				$start_open = strpos($text, '[' . $name, $start_open + 1); // allow [name= type tags
			}
		}

		if ($start_open === false) {
			return false;
		}

		$start_equal = strpos($text, '=', $start_open);
		$start_close = strpos($text, ']', $start_open);

		if ($start_close === false) {
			return false;
		}

		$start_close++;

		$end_open = strpos($text, '[/' . $name . ']', $start_close);

		if ($end_open === false) {
			return false;
		}

		$res = [
			'start' => [
				'open' => $start_open,
				'close' => $start_close
			],
			'end' => [
				'open' => $end_open,
				'close' => $end_open + strlen('[/' . $name . ']')
			],
		];

		if ($start_equal !== false) {
			$res['start']['equal'] = $start_equal + 1;
		}

		return $res;
	}

	/**
	 * Performs a preg_replace within the boundaries of all named BBCode tags in a text
	 *
	 * @param string $pattern Preg pattern string
	 * @param string $replace Preg replace string
	 * @param string $name    BBCode tag name
	 * @param string $text    Text to search
	 * @return string
	 */
	public static function pregReplaceInTag($pattern, $replace, $name, $text)
	{
		$occurrences = 0;
		$pos = self::getTagPosition($text, $name, $occurrences);
		while ($pos !== false && $occurrences++ < 1000) {
			$start = substr($text, 0, $pos['start']['open']);
			$subject = substr($text, $pos['start']['open'], $pos['end']['close'] - $pos['start']['open']);
			$end = substr($text, $pos['end']['close']);
			if ($end === false) {
				$end = '';
			}

			$subject = preg_replace($pattern, $replace, $subject);
			$text = $start . $subject . $end;

			$pos = self::getTagPosition($text, $name, $occurrences);
		}

		return $text;
	}

	private static function extractImagesFromItemBody($body)
	{
		$saved_image = [];
		$orig_body = $body;
		$new_body = '';

		$cnt = 0;
		$img_start = strpos($orig_body, '[img');
		$img_st_close = ($img_start !== false ? strpos(substr($orig_body, $img_start), ']') : false);
		$img_end = ($img_start !== false ? strpos(substr($orig_body, $img_start), '[/img]') : false);
		while (($img_st_close !== false) && ($img_end !== false)) {
			$img_st_close++; // make it point to AFTER the closing bracket
			$img_end += $img_start;

			if (!strcmp(substr($orig_body, $img_start + $img_st_close, 5), 'data:')) {
				// This is an embedded image
				$saved_image[$cnt] = substr($orig_body, $img_start + $img_st_close, $img_end - ($img_start + $img_st_close));
				$new_body = $new_body . substr($orig_body, 0, $img_start) . '[$#saved_image' . $cnt . '#$]';

				$cnt++;
			} else {
				$new_body = $new_body . substr($orig_body, 0, $img_end + strlen('[/img]'));
			}

			$orig_body = substr($orig_body, $img_end + strlen('[/img]'));

			if ($orig_body === false) {
				// in case the body ends on a closing image tag
				$orig_body = '';
			}

			$img_start = strpos($orig_body, '[img');
			$img_st_close = ($img_start !== false ? strpos(substr($orig_body, $img_start), ']') : false);
			$img_end = ($img_start !== false ? strpos(substr($orig_body, $img_start), '[/img]') : false);
		}

		$new_body = $new_body . $orig_body;

		return ['body' => $new_body, 'images' => $saved_image];
	}

	private static function interpolateSavedImagesIntoItemBody($body, array $images)
	{
		$newbody = $body;

		$cnt = 0;
		foreach ($images as $image) {
			// We're depending on the property of 'foreach' (specified on the PHP website) that
			// it loops over the array starting from the first element and going sequentially
			// to the last element
			$newbody = str_replace('[$#saved_image' . $cnt . '#$]',
				'<img src="' . self::proxyUrl($image) . '" alt="' . L10n::t('Image/photo') . '" />', $newbody);
			$cnt++;
		}

		return $newbody;
	}

	/**
	 * This function converts a [share] block to text according to a provided callback function whose signature is:
	 *
	 * function(array $attributes, array $author_contact, string $content, boolean $is_quote_share): string
	 *
	 * Where:
	 * - $attributes is an array of attributes of the [share] block itself. Missing keys will be completed by the contact
	 * data lookup
	 * - $author_contact is a contact record array
	 * - $content is the inner content of the [share] block
	 * - $is_quote_share indicates whether there's any content before the [share] block
	 * - Return value is the string that should replace the [share] block in the provided text
	 *
	 * This function is intended to be used by addon connector to format a share block like the target network is expecting it.
	 *
	 * @param  string   $text     A BBCode string
	 * @param  callable $callback
	 * @return string The BBCode string with all [share] blocks replaced
	 */
	public static function convertShare($text, callable $callback)
	{
		$return = preg_replace_callback(
			"/(.*?)\[share(.*?)\](.*?)\[\/share\]/ism",
			function ($match) use ($callback) {
				$attribute_string = $match[2];

				$attributes = [];
				foreach(['author', 'profile', 'avatar', 'link', 'posted'] as $field) {
					preg_match("/$field=(['\"])(.+?)\\1/ism", $attribute_string, $matches);
					$attributes[$field] = html_entity_decode(defaults($matches, 2, ''), ENT_QUOTES, 'UTF-8');
				}

				// We only call this so that a previously unknown contact can be added.
				// This is important for the function "Model\Contact::getDetailsByURL()".
				// This function then can fetch an entry from the contact table.
				$default['url'] = $attributes['profile'];

				if (!empty($attributes['author'])) {
					$default['name'] = $attributes['author'];
				}

				if (!empty($attributes['avatar'])) {
					$default['photo'] = $attributes['avatar'];
				}

				Contact::getIdForURL($attributes['profile'], 0, true, $default);

				$author_contact = Contact::getDetailsByURL($attributes['profile']);
				$author_contact['addr'] = defaults($author_contact, 'addr' , Protocol::getAddrFromProfileUrl($attributes['profile']));

				$attributes['author']   = defaults($author_contact, 'name' , $attributes['author']);
				$attributes['avatar']   = defaults($author_contact, 'micro', $attributes['avatar']);
				$attributes['profile']  = defaults($author_contact, 'url'  , $attributes['profile']);

				if ($attributes['avatar']) {
					$attributes['avatar'] = ProxyUtils::proxifyUrl($attributes['avatar'], false, ProxyUtils::SIZE_THUMB);
				}

				return $match[1] . $callback($attributes, $author_contact, $match[3], trim($match[1]) != '');
			},
			$text
		);

		return $return;
	}

	/**
	 * Default [share] tag conversion callback
	 *
	 * Note: Can produce a [bookmark] tag in the output
	 *
	 * @see BBCode::convertShare()
	 * @param array   $attributes     [share] block attribute values
	 * @param array   $author_contact Contact row of the shared author
	 * @param string  $content        Inner content of the [share] block
	 * @param boolean $is_quote_share Whether there is content before the [share] block
	 * @param integer $simplehtml     Mysterious integer value depending on the target network/formatting style
	 * @return string
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 */
	private static function convertShareCallback(array $attributes, array $author_contact, $content, $is_quote_share, $simplehtml)
	{
		$mention = Protocol::formatMention($attributes['profile'], $attributes['author']);

		switch ($simplehtml) {
			case 1:
				$text = ($is_quote_share? '<br />' : '') . '<p>' . html_entity_decode('&#x2672; ', ENT_QUOTES, 'UTF-8') . ' <a href="' . $attributes['profile'] . '">' . $mention . '</a>: </p>' . "\n" . '«' . $content . '»';
				break;
			case 2:
				$text = ($is_quote_share? '<br />' : '') . '<p>' . html_entity_decode('&#x2672; ', ENT_QUOTES, 'UTF-8') . ' ' . $author_contact['addr'] . ': </p>' . "\n" . $content;
				break;
			case 3: // Diaspora
				if (stripos(Strings::normaliseLink($attributes['link']), 'http://twitter.com/') === 0) {
					$text = ($is_quote_share? '<hr />' : '') . '<p><a href="' . $attributes['link'] . '">' . $attributes['link'] . '</a></p>' . "\n";
				} else {
					$headline = '<p><b>♲ <a href="' . $attributes['profile'] . '">' . $attributes['author'] . '</a>:</b></p>' . "\n";

					if (!empty($attributes['posted']) && !empty($attributes['link'])) {
						$headline = '<p><b>♲ <a href="' . $attributes['profile'] . '">' . $attributes['author'] . '</a></b> - <a href="' . $attributes['link'] . '">' . $attributes['posted'] . ' GMT</a></p>' . "\n";
					}

					$text = ($is_quote_share? '<hr />' : '') . $headline . '<blockquote>' . trim($content) . '</blockquote>' . "\n";

					if (empty($attributes['posted']) && !empty($attributes['link'])) {
						$text .= '<p><a href="' . $attributes['link'] . '">[Source]</a></p>' . "\n";
					}
				}

				break;
			case 4:
				$headline = '<p><b>' . html_entity_decode('&#x2672; ', ENT_QUOTES, 'UTF-8');
				$headline .= L10n::t('<a href="%1$s" target="_blank">%2$s</a> %3$s', $attributes['link'], $mention, $attributes['posted']);
				$headline .= ':</b></p>' . "\n";

				$text = ($is_quote_share? '<hr />' : '') . $headline . '<blockquote class="shared_content">' . trim($content) . '</blockquote>' . "\n";

				break;
			case 5:
				$text = ($is_quote_share? '<br />' : '') . '<p>' . html_entity_decode('&#x2672; ', ENT_QUOTES, 'UTF-8') . ' ' . $author_contact['addr'] . ': </p>' . "\n" . $content;
				break;
			case 7: // statusnet/GNU Social
			case 9: // ActivityPub
				$text = ($is_quote_share? '<br />' : '') . '<p>' . html_entity_decode('&#x2672; ', ENT_QUOTES, 'UTF-8') . ' @' . $author_contact['addr'] . ': ' . $content . '</p>' . "\n";
				break;
			default:
				// Transforms quoted tweets in rich attachments to avoid nested tweets
				if (stripos(Strings::normaliseLink($attributes['link']), 'http://twitter.com/') === 0 && OEmbed::isAllowedURL($attributes['link'])) {
					try {
						$text = ($is_quote_share? '<br />' : '') . OEmbed::getHTML($attributes['link']);
					} catch (Exception $e) {
						$text = ($is_quote_share? '<br />' : '') . sprintf('[bookmark=%s]%s[/bookmark]', $attributes['link'], $content);
					}
				} else {
					$text = ($is_quote_share? "\n" : '');

					$tpl = Renderer::getMarkupTemplate('shared_content.tpl');
					$text .= Renderer::replaceMacros($tpl, [
						'$profile' => $attributes['profile'],
						'$avatar'  => $attributes['avatar'],
						'$author'  => $attributes['author'],
						'$link'    => $attributes['link'],
						'$posted'  => $attributes['posted'],
						'$content' => trim($content)
					]);
				}
				break;
		}

		return $text;
	}

	private static function removePictureLinksCallback($match)
	{
		$text = Cache::get($match[1]);

		if (is_null($text)) {
			$a = self::getApp();

			$stamp1 = microtime(true);

			$ch = @curl_init($match[1]);
			@curl_setopt($ch, CURLOPT_NOBODY, true);
			@curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			@curl_setopt($ch, CURLOPT_USERAGENT, $a->getUserAgent());
			@curl_exec($ch);
			$curl_info = @curl_getinfo($ch);

			$a->getProfiler()->saveTimestamp($stamp1, "network", System::callstack());

			if (substr($curl_info["content_type"], 0, 6) == "image/") {
				$text = "[url=" . $match[1] . "]" . $match[1] . "[/url]";
			} else {
				$text = "[url=" . $match[2] . "]" . $match[2] . "[/url]";

				// if its not a picture then look if its a page that contains a picture link
				$body = Network::fetchUrl($match[1]);

				$doc = new DOMDocument();
				@$doc->loadHTML($body);
				$xpath = new DOMXPath($doc);
				$list = $xpath->query("//meta[@name]");
				foreach ($list as $node) {
					$attr = [];

					if ($node->attributes->length) {
						foreach ($node->attributes as $attribute) {
							$attr[$attribute->name] = $attribute->value;
						}
					}

					if (strtolower($attr["name"]) == "twitter:image") {
						$text = "[url=" . $attr["content"] . "]" . $attr["content"] . "[/url]";
					}
				}
			}
			Cache::set($match[1], $text);
		}

		return $text;
	}

	private static function expandLinksCallback($match)
	{
		if (($match[3] == "") || ($match[2] == $match[3]) || stristr($match[2], $match[3])) {
			return ($match[1] . "[url]" . $match[2] . "[/url]");
		} else {
			return ($match[1] . $match[3] . " [url]" . $match[2] . "[/url]");
		}
	}

	private static function cleanPictureLinksCallback($match)
	{
		$text = Cache::get($match[1]);

		if (is_null($text)) {
			$a = self::getApp();

			$stamp1 = microtime(true);

			$ch = @curl_init($match[1]);
			@curl_setopt($ch, CURLOPT_NOBODY, true);
			@curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			@curl_setopt($ch, CURLOPT_USERAGENT, $a->getUserAgent());
			@curl_exec($ch);
			$curl_info = @curl_getinfo($ch);

			$a->getProfiler()->saveTimestamp($stamp1, "network", System::callstack());

			// if its a link to a picture then embed this picture
			if (substr($curl_info["content_type"], 0, 6) == "image/") {
				$text = "[img]" . $match[1] . "[/img]";
			} else {
				if (!empty($match[3])) {
					$text = "[img=" . $match[2] . "]" . $match[3] . "[/img]";
				} else {
					$text = "[img]" . $match[2] . "[/img]";
				}

				// if its not a picture then look if its a page that contains a picture link
				$body = Network::fetchUrl($match[1]);

				$doc = new DOMDocument();
				@$doc->loadHTML($body);
				$xpath = new DOMXPath($doc);
				$list = $xpath->query("//meta[@name]");
				foreach ($list as $node) {
					$attr = [];
					if ($node->attributes->length) {
						foreach ($node->attributes as $attribute) {
							$attr[$attribute->name] = $attribute->value;
						}
					}

					if (strtolower($attr["name"]) == "twitter:image") {
						if (!empty($match[3])) {
							$text = "[img=" . $attr["content"] . "]" . $match[3] . "[/img]";
						} else {
							$text = "[img]" . $attr["content"] . "[/img]";
						}
					}
				}
			}
			Cache::set($match[1], $text);
		}

		return $text;
	}

	public static function cleanPictureLinks($text)
	{
		$return = preg_replace_callback("&\[url=([^\[\]]*)\]\[img=(.*)\](.*)\[\/img\]\[\/url\]&Usi", 'self::cleanPictureLinksCallback', $text);
		$return = preg_replace_callback("&\[url=([^\[\]]*)\]\[img\](.*)\[\/img\]\[\/url\]&Usi", 'self::cleanPictureLinksCallback', $return);
		return $return;
	}

	/**
	 * @brief Converts a BBCode message to HTML message
	 *
	 * BBcode 2 HTML was written by WAY2WEB.net
	 * extended to work with Mistpark/Friendica - Mike Macgirvin
	 *
	 * Simple HTML values meaning:
	 * - 0: Friendica display
	 * - 1: Unused
	 * - 2: Used for Windows Phone push, Friendica API
	 * - 3: Used before converting to Markdown in bb2diaspora.php
	 * - 4: Used for WordPress, Libertree (before Markdown), pump.io and tumblr
	 * - 5: Unused
	 * - 6: Unused
	 * - 7: Used for dfrn, OStatus
	 * - 8: Used for WP backlink text setting
	 * - 9: ActivityPub
	 *
	 * @param string $text
	 * @param bool   $try_oembed
	 * @param int    $simple_html
	 * @param bool   $for_plaintext
	 * @return string
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 */
	public static function convert($text, $try_oembed = true, $simple_html = 0, $for_plaintext = false)
	{
		$a = self::getApp();

		/*
		 * preg_match_callback function to replace potential Oembed tags with Oembed content
		 *
		 * $match[0] = [tag]$url[/tag] or [tag=$url]$title[/tag]
		 * $match[1] = $url
		 * $match[2] = $title or absent
		 */
		$try_oembed_callback = function ($match)
		{
			$url = $match[1];
			$title = defaults($match, 2, null);

			try {
				$return = OEmbed::getHTML($url, $title);
			} catch (Exception $ex) {
				$return = $match[0];
			}

			return $return;
		};

		// Extracting code blocks before the whitespace processing and the autolinker
		$codeblocks = [];

		$text = preg_replace_callback("#\[code(?:=([^\]]*))?\](.*?)\[\/code\]#ism",
			function ($matches) use (&$codeblocks) {
				$return = '#codeblock-' . count($codeblocks) . '#';
				if (strpos($matches[2], "\n") !== false) {
					$codeblocks[] = '<pre><code class="language-' . trim($matches[1]) . '">' . trim($matches[2], "\n\r") . '</code></pre>';
				} else {
					$codeblocks[] = '<code>' . $matches[2] . '</code>';
				}

				return $return;
			},
			$text
		);

		// Hide all [noparse] contained bbtags by spacefying them
		// POSSIBLE BUG --> Will the 'preg' functions crash if there's an embedded image?

		$text = preg_replace_callback("/\[noparse\](.*?)\[\/noparse\]/ism", 'self::escapeNoparseCallback', $text);
		$text = preg_replace_callback("/\[nobb\](.*?)\[\/nobb\]/ism", 'self::escapeNoparseCallback', $text);
		$text = preg_replace_callback("/\[pre\](.*?)\[\/pre\]/ism", 'self::escapeNoparseCallback', $text);

		// Remove the abstract element. It is a non visible element.
		$text = self::stripAbstract($text);

		// Move all spaces out of the tags
		$text = preg_replace("/\[(\w*)\](\s*)/ism", '$2[$1]', $text);
		$text = preg_replace("/(\s*)\[\/(\w*)\]/ism", '[/$2]$1', $text);

		// Extract the private images which use data urls since preg has issues with
		// large data sizes. Stash them away while we do bbcode conversion, and then put them back
		// in after we've done all the regex matching. We cannot use any preg functions to do this.

		$extracted = self::extractImagesFromItemBody($text);
		$text = $extracted['body'];
		$saved_image = $extracted['images'];

		// If we find any event code, turn it into an event.
		// After we're finished processing the bbcode we'll
		// replace all of the event code with a reformatted version.

		$ev = Event::fromBBCode($text);

		// Replace any html brackets with HTML Entities to prevent executing HTML or script
		// Don't use strip_tags here because it breaks [url] search by replacing & with amp

		$text = str_replace("<", "&lt;", $text);
		$text = str_replace(">", "&gt;", $text);

		// remove some newlines before the general conversion
		$text = preg_replace("/\s?\[share(.*?)\]\s?(.*?)\s?\[\/share\]\s?/ism", "[share$1]$2[/share]", $text);
		$text = preg_replace("/\s?\[quote(.*?)\]\s?(.*?)\s?\[\/quote\]\s?/ism", "[quote$1]$2[/quote]", $text);

		// when the content is meant exporting to other systems then remove the avatar picture since this doesn't really look good on these systems
		if (!$try_oembed) {
			$text = preg_replace("/\[share(.*?)avatar\s?=\s?'.*?'\s?(.*?)\]\s?(.*?)\s?\[\/share\]\s?/ism", "\n[share$1$2]$3[/share]", $text);
		}

		// Convert new line chars to html <br /> tags

		// nlbr seems to be hopelessly messed up
		//	$Text = nl2br($Text);

		// We'll emulate it.

		$text = trim($text);
		$text = str_replace("\r\n", "\n", $text);

		// Remove linefeeds inside of the table elements. See issue #6799
		$search = ["\n[th]", "[th]\n", " [th]", "\n[/th]", "[/th]\n", "[/th] ",
			"\n[td]", "[td]\n", " [td]", "\n[/td]", "[/td]\n", "[/td] ",
			"\n[tr]", "[tr]\n", " [tr]", "[tr] ", "\n[/tr]", "[/tr]\n", " [/tr]", "[/tr] ",
			"[table]\n", "[table] ", " [table]", "\n[/table]", " [/table]", "[/table] "];
		$replace = ["[th]", "[th]", "[th]", "[/th]", "[/th]", "[/th]",
			"[td]", "[td]", "[td]", "[/td]", "[/td]", "[/td]",
			"[tr]", "[tr]", "[tr]", "[tr]", "[/tr]", "[/tr]", "[/tr]", "[/tr]",
			"[table]", "[table]", "[table]", "[/table]", "[/table]", "[/table]"];
		do {
			$oldtext = $text;
			$text = str_replace($search, $replace, $text);
		} while ($oldtext != $text);

		// Replace these here only once
		$search = ["\n[table]", "[/table]\n"];
		$replace = ["[table]", "[/table]"];
		$text = str_replace($search, $replace, $text);

		// removing multiplicated newlines
		if (Config::get("system", "remove_multiplicated_lines")) {
			$search = ["\n\n\n", "\n ", " \n", "[/quote]\n\n", "\n[/quote]", "[/li]\n", "\n[li]", "\n[ul]", "[/ul]\n", "\n\n[share ", "[/attachment]\n",
					"\n[h1]", "[/h1]\n", "\n[h2]", "[/h2]\n", "\n[h3]", "[/h3]\n", "\n[h4]", "[/h4]\n", "\n[h5]", "[/h5]\n", "\n[h6]", "[/h6]\n"];
			$replace = ["\n\n", "\n", "\n", "[/quote]\n", "[/quote]", "[/li]", "[li]", "[ul]", "[/ul]", "\n[share ", "[/attachment]",
					"[h1]", "[/h1]", "[h2]", "[/h2]", "[h3]", "[/h3]", "[h4]", "[/h4]", "[h5]", "[/h5]", "[h6]", "[/h6]"];
			do {
				$oldtext = $text;
				$text = str_replace($search, $replace, $text);
			} while ($oldtext != $text);
		}

		// Handle attached links or videos
		$text = self::convertAttachment($text, $simple_html, $try_oembed);

		// leave open the posibility of [map=something]
		// this is replaced in Item::prepareBody() which has knowledge of the item location
		if (strpos($text, '[/map]') !== false) {
			$text = preg_replace_callback(
				"/\[map\](.*?)\[\/map\]/ism",
				function ($match) use ($simple_html) {
					return str_replace($match[0], '<p class="map">' . Map::byLocation($match[1], $simple_html) . '</p>', $match[0]);
				},
				$text
			);
		}

		if (strpos($text, '[map=') !== false) {
			$text = preg_replace_callback(
				"/\[map=(.*?)\]/ism",
				function ($match) use ($simple_html) {
					return str_replace($match[0], '<p class="map">' . Map::byCoordinates(str_replace('/', ' ', $match[1]), $simple_html) . '</p>', $match[0]);
				},
				$text
			);
		}

		if (strpos($text, '[map]') !== false) {
			$text = preg_replace("/\[map\]/", '<p class="map"></p>', $text);
		}

		// Check for headers
		$text = preg_replace("(\[h1\](.*?)\[\/h1\])ism", '<h1>$1</h1>', $text);
		$text = preg_replace("(\[h2\](.*?)\[\/h2\])ism", '<h2>$1</h2>', $text);
		$text = preg_replace("(\[h3\](.*?)\[\/h3\])ism", '<h3>$1</h3>', $text);
		$text = preg_replace("(\[h4\](.*?)\[\/h4\])ism", '<h4>$1</h4>', $text);
		$text = preg_replace("(\[h5\](.*?)\[\/h5\])ism", '<h5>$1</h5>', $text);
		$text = preg_replace("(\[h6\](.*?)\[\/h6\])ism", '<h6>$1</h6>', $text);

		// Check for paragraph
		$text = preg_replace("(\[p\](.*?)\[\/p\])ism", '<p>$1</p>', $text);

		// Check for bold text
		$text = preg_replace("(\[b\](.*?)\[\/b\])ism", '<strong>$1</strong>', $text);

		// Check for Italics text
		$text = preg_replace("(\[i\](.*?)\[\/i\])ism", '<em>$1</em>', $text);

		// Check for Underline text
		$text = preg_replace("(\[u\](.*?)\[\/u\])ism", '<u>$1</u>', $text);

		// Check for strike-through text
		$text = preg_replace("(\[s\](.*?)\[\/s\])ism", '<s>$1</s>', $text);

		// Check for over-line text
		$text = preg_replace("(\[o\](.*?)\[\/o\])ism", '<span class="overline">$1</span>', $text);

		// Check for colored text
		$text = preg_replace("(\[color=(.*?)\](.*?)\[\/color\])ism", "<span style=\"color: $1;\">$2</span>", $text);

		// Check for sized text
		// [size=50] --> font-size: 50px (with the unit).
		$text = preg_replace("(\[size=(\d*?)\](.*?)\[\/size\])ism", "<span style=\"font-size: $1px; line-height: initial;\">$2</span>", $text);
		$text = preg_replace("(\[size=(.*?)\](.*?)\[\/size\])ism", "<span style=\"font-size: $1; line-height: initial;\">$2</span>", $text);

		// Check for centered text
		$text = preg_replace("(\[center\](.*?)\[\/center\])ism", "<div style=\"text-align:center;\">$1</div>", $text);

		// Check for list text
		$text = str_replace("[*]", "<li>", $text);

		// Check for style sheet commands
		$text = preg_replace_callback(
			"(\[style=(.*?)\](.*?)\[\/style\])ism",
			function ($match) {
				return "<span style=\"" . HTML::sanitizeCSS($match[1]) . ";\">" . $match[2] . "</span>";
			},
			$text
		);

		// Check for CSS classes
		$text = preg_replace_callback(
			"(\[class=(.*?)\](.*?)\[\/class\])ism",
			function ($match) {
				return "<span class=\"" . HTML::sanitizeCSS($match[1]) . "\">" . $match[2] . "</span>";
			},
			$text
		);

		// handle nested lists
		$endlessloop = 0;

		while ((((strpos($text, "[/list]") !== false) && (strpos($text, "[list") !== false)) ||
				((strpos($text, "[/ol]") !== false) && (strpos($text, "[ol]") !== false)) ||
				((strpos($text, "[/ul]") !== false) && (strpos($text, "[ul]") !== false)) ||
				((strpos($text, "[/li]") !== false) && (strpos($text, "[li]") !== false))) && (++$endlessloop < 20)) {
			$text = preg_replace("/\[list\](.*?)\[\/list\]/ism", '<ul class="listbullet" style="list-style-type: circle;">$1</ul>', $text);
			$text = preg_replace("/\[list=\](.*?)\[\/list\]/ism", '<ul class="listnone" style="list-style-type: none;">$1</ul>', $text);
			$text = preg_replace("/\[list=1\](.*?)\[\/list\]/ism", '<ul class="listdecimal" style="list-style-type: decimal;">$1</ul>', $text);
			$text = preg_replace("/\[list=((?-i)i)\](.*?)\[\/list\]/ism", '<ul class="listlowerroman" style="list-style-type: lower-roman;">$2</ul>', $text);
			$text = preg_replace("/\[list=((?-i)I)\](.*?)\[\/list\]/ism", '<ul class="listupperroman" style="list-style-type: upper-roman;">$2</ul>', $text);
			$text = preg_replace("/\[list=((?-i)a)\](.*?)\[\/list\]/ism", '<ul class="listloweralpha" style="list-style-type: lower-alpha;">$2</ul>', $text);
			$text = preg_replace("/\[list=((?-i)A)\](.*?)\[\/list\]/ism", '<ul class="listupperalpha" style="list-style-type: upper-alpha;">$2</ul>', $text);
			$text = preg_replace("/\[ul\](.*?)\[\/ul\]/ism", '<ul class="listbullet" style="list-style-type: circle;">$1</ul>', $text);
			$text = preg_replace("/\[ol\](.*?)\[\/ol\]/ism", '<ul class="listdecimal" style="list-style-type: decimal;">$1</ul>', $text);
			$text = preg_replace("/\[li\](.*?)\[\/li\]/ism", '<li>$1</li>', $text);
		}

		$text = preg_replace("/\[th\](.*?)\[\/th\]/sm", '<th>$1</th>', $text);
		$text = preg_replace("/\[td\](.*?)\[\/td\]/sm", '<td>$1</td>', $text);
		$text = preg_replace("/\[tr\](.*?)\[\/tr\]/sm", '<tr>$1</tr>', $text);
		$text = preg_replace("/\[table\](.*?)\[\/table\]/sm", '<table>$1</table>', $text);

		$text = preg_replace("/\[table border=1\](.*?)\[\/table\]/sm", '<table border="1" >$1</table>', $text);
		$text = preg_replace("/\[table border=0\](.*?)\[\/table\]/sm", '<table border="0" >$1</table>', $text);

		$text = str_replace('[hr]', '<hr />', $text);

		// This is actually executed in Item::prepareBody()

		$nosmile = strpos($text, '[nosmile]') !== false;
		$text = str_replace('[nosmile]', '', $text);

		// Check for font change text
		$text = preg_replace("/\[font=(.*?)\](.*?)\[\/font\]/sm", "<span style=\"font-family: $1;\">$2</span>", $text);

		// Declare the format for [spoiler] layout
		$SpoilerLayout = '<blockquote class="spoiler">$1</blockquote>';

		// Check for [spoiler] text
		// handle nested quotes
		$endlessloop = 0;
		while ((strpos($text, "[/spoiler]") !== false) && (strpos($text, "[spoiler]") !== false) && (++$endlessloop < 20)) {
			$text = preg_replace("/\[spoiler\](.*?)\[\/spoiler\]/ism", "$SpoilerLayout", $text);
		}

		// Check for [spoiler=Author] text

		$t_wrote = L10n::t('$1 wrote:');

		// handle nested quotes
		$endlessloop = 0;
		while ((strpos($text, "[/spoiler]")!== false)  && (strpos($text, "[spoiler=") !== false) && (++$endlessloop < 20)) {
			$text = preg_replace("/\[spoiler=[\"\']*(.*?)[\"\']*\](.*?)\[\/spoiler\]/ism",
				"<br /><strong class=".'"spoiler"'.">" . $t_wrote . "</strong><blockquote class=".'"spoiler"'.">$2</blockquote>",
				$text);
		}

		// Declare the format for [quote] layout
		$QuoteLayout = '<blockquote>$1</blockquote>';

		// Check for [quote] text
		// handle nested quotes
		$endlessloop = 0;
		while ((strpos($text, "[/quote]") !== false) && (strpos($text, "[quote]") !== false) && (++$endlessloop < 20)) {
			$text = preg_replace("/\[quote\](.*?)\[\/quote\]/ism", "$QuoteLayout", $text);
		}

		// Check for [quote=Author] text

		$t_wrote = L10n::t('$1 wrote:');

		// handle nested quotes
		$endlessloop = 0;
		while ((strpos($text, "[/quote]")!== false)  && (strpos($text, "[quote=") !== false) && (++$endlessloop < 20)) {
			$text = preg_replace("/\[quote=[\"\']*(.*?)[\"\']*\](.*?)\[\/quote\]/ism",
				"<p><strong class=".'"author"'.">" . $t_wrote . "</strong></p><blockquote>$2</blockquote>",
				$text);
		}


		// [img=widthxheight]image source[/img]
		$text = preg_replace_callback(
			"/\[img\=([0-9]*)x([0-9]*)\](.*?)\[\/img\]/ism",
			function ($matches) use ($simple_html) {
				if (strpos($matches[3], "data:image/") === 0) {
					return $matches[0];
				}

				$matches[3] = self::proxyUrl($matches[3], $simple_html);
				return "[img=" . $matches[1] . "x" . $matches[2] . "]" . $matches[3] . "[/img]";
			},
			$text
		);

		$text = preg_replace("/\[img\=([0-9]*)x([0-9]*)\](.*?)\[\/img\]/ism", '<img src="$3" style="width: $1px;" >', $text);
		$text = preg_replace("/\[zmg\=([0-9]*)x([0-9]*)\](.*?)\[\/zmg\]/ism", '<img class="zrl" src="$3" style="width: $1px;" >', $text);

		$text = preg_replace_callback("/\[img\=(.*?)\](.*?)\[\/img\]/ism",
			function ($matches) use ($simple_html) {
				$matches[1] = self::proxyUrl($matches[1], $simple_html);
				$matches[2] = htmlspecialchars($matches[2], ENT_COMPAT);
				return '<img src="' . $matches[1] . '" alt="' . $matches[2] . '">';
			},
			$text);

		// Images
		// [img]pathtoimage[/img]
		$text = preg_replace_callback(
			"/\[img\](.*?)\[\/img\]/ism",
			function ($matches) use ($simple_html) {
				if (strpos($matches[1], "data:image/") === 0) {
					return $matches[0];
				}

				$matches[1] = self::proxyUrl($matches[1], $simple_html);
				return "[img]" . $matches[1] . "[/img]";
			},
			$text
		);

		$text = preg_replace("/\[img\](.*?)\[\/img\]/ism", '<img src="$1" alt="' . L10n::t('Image/photo') . '" />', $text);
		$text = preg_replace("/\[zmg\](.*?)\[\/zmg\]/ism", '<img src="$1" alt="' . L10n::t('Image/photo') . '" />', $text);

		$text = preg_replace("/\[crypt\](.*?)\[\/crypt\]/ism", '<br/><img src="' .System::baseUrl() . '/images/lock_icon.gif" alt="' . L10n::t('Encrypted content') . '" title="' . L10n::t('Encrypted content') . '" /><br />', $text);
		$text = preg_replace("/\[crypt(.*?)\](.*?)\[\/crypt\]/ism", '<br/><img src="' .System::baseUrl() . '/images/lock_icon.gif" alt="' . L10n::t('Encrypted content') . '" title="' . '$1' . ' ' . L10n::t('Encrypted content') . '" /><br />', $text);
		//$Text = preg_replace("/\[crypt=(.*?)\](.*?)\[\/crypt\]/ism", '<br/><img src="' .System::baseUrl() . '/images/lock_icon.gif" alt="' . L10n::t('Encrypted content') . '" title="' . '$1' . ' ' . L10n::t('Encrypted content') . '" /><br />', $Text);

		// Try to Oembed
		if ($try_oembed) {
			$text = preg_replace("/\[video\](.*?\.(ogg|ogv|oga|ogm|webm|mp4).*?)\[\/video\]/ism", '<video src="$1" controls="controls" width="' . $a->videowidth . '" height="' . $a->videoheight . '" loop="true"><a href="$1">$1</a></video>', $text);
			$text = preg_replace("/\[audio\](.*?\.(ogg|ogv|oga|ogm|webm|mp4|mp3).*?)\[\/audio\]/ism", '<audio src="$1" controls="controls"><a href="$1">$1</a></audio>', $text);

			$text = preg_replace_callback("/\[video\](.*?)\[\/video\]/ism", $try_oembed_callback, $text);
			$text = preg_replace_callback("/\[audio\](.*?)\[\/audio\]/ism", $try_oembed_callback, $text);
		} else {
			$text = preg_replace("/\[video\](.*?)\[\/video\]/ism",
				'<a href="$1" target="_blank">$1</a>', $text);
			$text = preg_replace("/\[audio\](.*?)\[\/audio\]/ism",
				'<a href="$1" target="_blank">$1</a>', $text);
		}

		// html5 video and audio


		if ($try_oembed) {
			$text = preg_replace("/\[iframe\](.*?)\[\/iframe\]/ism", '<iframe src="$1" width="' . $a->videowidth . '" height="' . $a->videoheight . '"><a href="$1">$1</a></iframe>', $text);
		} else {
			$text = preg_replace("/\[iframe\](.*?)\[\/iframe\]/ism", '<a href="$1">$1</a>', $text);
		}

		// Youtube extensions
		if ($try_oembed) {
			$text = preg_replace_callback("/\[youtube\](https?:\/\/www.youtube.com\/watch\?v\=.*?)\[\/youtube\]/ism", $try_oembed_callback, $text);
			$text = preg_replace_callback("/\[youtube\](www.youtube.com\/watch\?v\=.*?)\[\/youtube\]/ism", $try_oembed_callback, $text);
			$text = preg_replace_callback("/\[youtube\](https?:\/\/youtu.be\/.*?)\[\/youtube\]/ism", $try_oembed_callback, $text);
		}

		$text = preg_replace("/\[youtube\]https?:\/\/www.youtube.com\/watch\?v\=(.*?)\[\/youtube\]/ism", '[youtube]$1[/youtube]', $text);
		$text = preg_replace("/\[youtube\]https?:\/\/www.youtube.com\/embed\/(.*?)\[\/youtube\]/ism", '[youtube]$1[/youtube]', $text);
		$text = preg_replace("/\[youtube\]https?:\/\/youtu.be\/(.*?)\[\/youtube\]/ism", '[youtube]$1[/youtube]', $text);

		if ($try_oembed) {
			$text = preg_replace("/\[youtube\]([A-Za-z0-9\-_=]+)(.*?)\[\/youtube\]/ism", '<iframe width="' . $a->videowidth . '" height="' . $a->videoheight . '" src="https://www.youtube.com/embed/$1" frameborder="0" ></iframe>', $text);
		} else {
			$text = preg_replace("/\[youtube\]([A-Za-z0-9\-_=]+)(.*?)\[\/youtube\]/ism",
				'<a href="https://www.youtube.com/watch?v=$1" target="_blank">https://www.youtube.com/watch?v=$1</a>', $text);
		}

		if ($try_oembed) {
			$text = preg_replace_callback("/\[vimeo\](https?:\/\/player.vimeo.com\/video\/[0-9]+).*?\[\/vimeo\]/ism", $try_oembed_callback, $text);
			$text = preg_replace_callback("/\[vimeo\](https?:\/\/vimeo.com\/[0-9]+).*?\[\/vimeo\]/ism", $try_oembed_callback, $text);
		}

		$text = preg_replace("/\[vimeo\]https?:\/\/player.vimeo.com\/video\/([0-9]+)(.*?)\[\/vimeo\]/ism", '[vimeo]$1[/vimeo]', $text);
		$text = preg_replace("/\[vimeo\]https?:\/\/vimeo.com\/([0-9]+)(.*?)\[\/vimeo\]/ism", '[vimeo]$1[/vimeo]', $text);

		if ($try_oembed) {
			$text = preg_replace("/\[vimeo\]([0-9]+)(.*?)\[\/vimeo\]/ism", '<iframe width="' . $a->videowidth . '" height="' . $a->videoheight . '" src="https://player.vimeo.com/video/$1" frameborder="0" ></iframe>', $text);
		} else {
			$text = preg_replace("/\[vimeo\]([0-9]+)(.*?)\[\/vimeo\]/ism",
				'<a href="https://vimeo.com/$1" target="_blank">https://vimeo.com/$1</a>', $text);
		}

		// oembed tag
		$text = OEmbed::BBCode2HTML($text);

		// Avoid triple linefeeds through oembed
		$text = str_replace("<br style='clear:left'></span><br /><br />", "<br style='clear:left'></span><br />", $text);

		// If we found an event earlier, strip out all the event code and replace with a reformatted version.
		// Replace the event-start section with the entire formatted event. The other bbcode is stripped.
		// Summary (e.g. title) is required, earlier revisions only required description (in addition to
		// start which is always required). Allow desc with a missing summary for compatibility.

		if ((!empty($ev['desc']) || !empty($ev['summary'])) && !empty($ev['start'])) {
			$sub = Event::getHTML($ev, $simple_html);

			$text = preg_replace("/\[event\-summary\](.*?)\[\/event\-summary\]/ism", '', $text);
			$text = preg_replace("/\[event\-description\](.*?)\[\/event\-description\]/ism", '', $text);
			$text = preg_replace("/\[event\-start\](.*?)\[\/event\-start\]/ism", $sub, $text);
			$text = preg_replace("/\[event\-finish\](.*?)\[\/event\-finish\]/ism", '', $text);
			$text = preg_replace("/\[event\-location\](.*?)\[\/event\-location\]/ism", '', $text);
			$text = preg_replace("/\[event\-adjust\](.*?)\[\/event\-adjust\]/ism", '', $text);
			$text = preg_replace("/\[event\-id\](.*?)\[\/event\-id\]/ism", '', $text);
		}

		// Replace non graphical smilies for external posts
		if (!$nosmile && !$for_plaintext) {
			$text = Smilies::replace($text);
		}

		// if the HTML is used to generate plain text, then don't do this search, but replace all URL of that kind to text
		if (!$for_plaintext) {
			$text = preg_replace(Strings::autoLinkRegEx(), '[url]$1[/url]', $text);
			if (in_array($simple_html, [7, 9])) {
				$text = preg_replace_callback("/\[url\](.*?)\[\/url\]/ism", 'self::convertUrlForOStatusCallback', $text);
				$text = preg_replace_callback("/\[url\=(.*?)\](.*?)\[\/url\]/ism", 'self::convertUrlForOStatusCallback', $text);
			}
		} else {
			$text = preg_replace("(\[url\](.*?)\[\/url\])ism", " $1 ", $text);
			$text = preg_replace_callback("&\[url=([^\[\]]*)\]\[img\](.*)\[\/img\]\[\/url\]&Usi", 'self::removePictureLinksCallback', $text);
		}

		$text = str_replace(["\r","\n"], ['<br />', '<br />'], $text);

		// Remove all hashtag addresses
		if ((!$try_oembed || $simple_html) && !in_array($simple_html, [3, 7, 9])) {
			$text = preg_replace("/([#@!])\[url\=(.*?)\](.*?)\[\/url\]/ism", '$1$3', $text);
		} elseif ($simple_html == 3) {
			// The ! is converted to @ since Diaspora only understands the @
			$text = preg_replace("/([@!])\[url\=(.*?)\](.*?)\[\/url\]/ism",
				'@<a href="$2">$3</a>',
				$text);
		} elseif (in_array($simple_html, [7, 9])) {
			$text = preg_replace("/([@!])\[url\=(.*?)\](.*?)\[\/url\]/ism",
				'$1<span class="vcard"><a href="$2" class="url u-url mention" title="$3"><span class="fn nickname mention">$3</span></a></span>',
				$text);
		} elseif (!$simple_html) {
			$text = preg_replace("/([@!])\[url\=(.*?)\](.*?)\[\/url\]/ism",
				'$1<a href="$2" class="userinfo mention" title="$3">$3</a>',
				$text);
		}

		// Bookmarks in red - will be converted to bookmarks in friendica
		$text = preg_replace("/#\^\[url\](.*?)\[\/url\]/ism", '[bookmark=$1]$1[/bookmark]', $text);
		$text = preg_replace("/#\^\[url\=(.*?)\](.*?)\[\/url\]/ism", '[bookmark=$1]$2[/bookmark]', $text);
		$text = preg_replace("/#\[url\=.*?\]\^\[\/url\]\[url\=(.*?)\](.*?)\[\/url\]/i",
					"[bookmark=$1]$2[/bookmark]", $text);

		if (in_array($simple_html, [2, 6, 7, 8])) {
			$text = preg_replace_callback("/([^#@!])\[url\=([^\]]*)\](.*?)\[\/url\]/ism", "self::expandLinksCallback", $text);
			//$Text = preg_replace("/[^#@!]\[url\=([^\]]*)\](.*?)\[\/url\]/ism", ' $2 [url]$1[/url]', $Text);
			$text = preg_replace("/\[bookmark\=([^\]]*)\](.*?)\[\/bookmark\]/ism", ' $2 [url]$1[/url]',$text);
		}

		if ($simple_html == 5) {
			$text = preg_replace("/[^#@!]\[url\=(.*?)\](.*?)\[\/url\]/ism", '[url]$1[/url]', $text);
		}

		// Perform URL Search
		if ($try_oembed) {
			$text = preg_replace_callback("/\[bookmark\=([^\]]*)\](.*?)\[\/bookmark\]/ism", $try_oembed_callback, $text);
		}

		if ($simple_html == 5) {
			$text = preg_replace("/\[bookmark\=([^\]]*)\](.*?)\[\/bookmark\]/ism", '[url]$1[/url]', $text);
		} else {
			$text = preg_replace("/\[bookmark\=([^\]]*)\](.*?)\[\/bookmark\]/ism", '[url=$1]$2[/url]', $text);
		}

		// Handle Diaspora posts
		$text = preg_replace_callback(
			"&\[url=/?posts/([^\[\]]*)\](.*)\[\/url\]&Usi",
			function ($match) {
				return "[url=" . System::baseUrl() . "/display/" . $match[1] . "]" . $match[2] . "[/url]";
			}, $text
		);

		$text = preg_replace_callback(
			"&\[url=/people\?q\=(.*)\](.*)\[\/url\]&Usi",
			function ($match) {
				return "[url=" . System::baseUrl() . "/search?search=%40" . $match[1] . "]" . $match[2] . "[/url]";
			}, $text
		);

		// Server independent link to posts and comments
		// See issue: https://github.com/diaspora/diaspora_federation/issues/75
		$expression = "=diaspora://.*?/post/([0-9A-Za-z\-_@.:]{15,254}[0-9A-Za-z])=ism";
		$text = preg_replace($expression, System::baseUrl()."/display/$1", $text);

		/* Tag conversion
		 * Supports:
		 * - #[url=<anything>]<term>[/url]
		 * - [url=<anything>]#<term>[/url]
		 */
		$text = preg_replace_callback("/(?:#\[url\=.*?\]|\[url\=.*?\]#)(.*?)\[\/url\]/ism", function($matches) {
			return '#<a href="'
				. System::baseUrl()	. '/search?tag=' . rawurlencode($matches[1])
				. '" class="tag" title="' . XML::escape($matches[1]) . '">'
				. XML::escape($matches[1])
				. '</a>';
		}, $text);

		// We need no target="_blank" for local links
		// convert links start with System::baseUrl() as local link without the target="_blank" attribute
		$escapedBaseUrl = preg_quote(System::baseUrl(), '/');
		$text = preg_replace("/\[url\](".$escapedBaseUrl.".*?)\[\/url\]/ism", '<a href="$1">$1</a>', $text);
		$text = preg_replace("/\[url\=(".$escapedBaseUrl.".*?)\](.*?)\[\/url\]/ism", '<a href="$1">$2</a>', $text);

		$text = preg_replace("/\[url\](.*?)\[\/url\]/ism", '<a href="$1" target="_blank">$1</a>', $text);
		$text = preg_replace("/\[url\=(.*?)\](.*?)\[\/url\]/ism", '<a href="$1" target="_blank">$2</a>', $text);

		// Red compatibility, though the link can't be authenticated on Friendica
		$text = preg_replace("/\[zrl\=(.*?)\](.*?)\[\/zrl\]/ism", '<a href="$1" target="_blank">$2</a>', $text);


		// we may need to restrict this further if it picks up too many strays
		// link acct:user@host to a webfinger profile redirector

		$text = preg_replace('/acct:([^@]+)@((?!\-)(?:[a-zA-Z\d\-]{0,62}[a-zA-Z\d]\.){1,126}(?!\d+)[a-zA-Z\d]{1,63})/', '<a href="' . System::baseUrl() . '/acctlink?addr=$1@$2" target="extlink">acct:$1@$2</a>', $text);

		// Perform MAIL Search
		$text = preg_replace("/\[mail\](.*?)\[\/mail\]/", '<a href="mailto:$1">$1</a>', $text);
		$text = preg_replace("/\[mail\=(.*?)\](.*?)\[\/mail\]/", '<a href="mailto:$1">$2</a>', $text);

		// Unhide all [noparse] contained bbtags unspacefying them
		// and triming the [noparse] tag.

		$text = preg_replace_callback("/\[noparse\](.*?)\[\/noparse\]/ism", 'self::unescapeNoparseCallback', $text);
		$text = preg_replace_callback("/\[nobb\](.*?)\[\/nobb\]/ism", 'self::unescapeNoparseCallback', $text);
		$text = preg_replace_callback("/\[pre\](.*?)\[\/pre\]/ism", 'self::unescapeNoparseCallback', $text);

		/// @todo What is the meaning of these lines?
		$text = preg_replace('/\[\&amp\;([#a-z0-9]+)\;\]/', '&$1;', $text);
		$text = preg_replace('/\&\#039\;/', '\'', $text);

		// Currently deactivated, it made problems with " inside of alt texts.
		//$text = preg_replace('/\&quot\;/', '"', $text);

		// fix any escaped ampersands that may have been converted into links
		$text = preg_replace('/\<([^>]*?)(src|href)=(.*?)\&amp\;(.*?)\>/ism', '<$1$2=$3&$4>', $text);

		// sanitizes src attributes (http and redir URLs for displaying in a web page, cid used for inline images in emails)
		$allowed_src_protocols = ['//', 'http://', 'https://', 'redir/', 'cid:'];

		array_walk($allowed_src_protocols, function(&$value) { $value = preg_quote($value, '#');});

		$text = preg_replace('#<([^>]*?)(src)="(?!' . implode('|', $allowed_src_protocols) . ')(.*?)"(.*?)>#ism',
					 '<$1$2=""$4 data-original-src="$3" class="invalid-src" title="' . L10n::t('Invalid source protocol') . '">', $text);

		// sanitize href attributes (only whitelisted protocols URLs)
		// default value for backward compatibility
		$allowed_link_protocols = Config::get('system', 'allowed_link_protocols', []);

		// Always allowed protocol even if config isn't set or not including it
		$allowed_link_protocols[] = '//';
		$allowed_link_protocols[] = 'http://';
		$allowed_link_protocols[] = 'https://';
		$allowed_link_protocols[] = 'redir/';

		array_walk($allowed_link_protocols, function(&$value) { $value = preg_quote($value, '#');});

		$regex = '#<([^>]*?)(href)="(?!' . implode('|', $allowed_link_protocols) . ')(.*?)"(.*?)>#ism';
		$text = preg_replace($regex, '<$1$2="javascript:void(0)"$4 data-original-href="$3" class="invalid-href" title="' . L10n::t('Invalid link protocol') . '">', $text);

		// Shared content
		$text = self::convertShare(
			$text,
			function (array $attributes, array $author_contact, $content, $is_quote_share) use ($simple_html) {
				return self::convertShareCallback($attributes, $author_contact, $content, $is_quote_share, $simple_html);
			}
		);

		if ($saved_image) {
			$text = self::interpolateSavedImagesIntoItemBody($text, $saved_image);
		}

		// Restore code blocks
		$text = preg_replace_callback('/#codeblock-([0-9]+)#/iU',
			function ($matches) use ($codeblocks) {
				$return = $matches[0];
				if (isset($codeblocks[intval($matches[1])])) {
					$return = $codeblocks[$matches[1]];
				}
				return $return;
			},
			$text
		);

		// Clean up the HTML by loading and saving the HTML with the DOM.
		// Bad structured html can break a whole page.
		// For performance reasons do it only with activated item cache or at export.
		if (!$try_oembed || (get_itemcachepath() != "")) {
			$doc = new DOMDocument();
			$doc->preserveWhiteSpace = false;

			$text = mb_convert_encoding($text, 'HTML-ENTITIES', "UTF-8");

			$doctype = '<!DOCTYPE html PUBLIC "-//W3C//DTD HTML 4.0 Transitional//EN" "http://www.w3.org/TR/REC-html40/loose.dtd">';
			$encoding = '<?xml encoding="UTF-8">';
			@$doc->loadHTML($encoding.$doctype."<html><body>".$text."</body></html>");
			$doc->encoding = 'UTF-8';
			$text = $doc->saveHTML();
			$text = str_replace(["<html><body>", "</body></html>", $doctype, $encoding], ["", "", "", ""], $text);

			$text = str_replace('<br></li>', '</li>', $text);

			//$Text = mb_convert_encoding($Text, "UTF-8", 'HTML-ENTITIES');
		}

		// Clean up some useless linebreaks in lists
		//$Text = str_replace('<br /><ul', '<ul ', $Text);
		//$Text = str_replace('</ul><br />', '</ul>', $Text);
		//$Text = str_replace('</li><br />', '</li>', $Text);
		//$Text = str_replace('<br /><li>', '<li>', $Text);
		//$Text = str_replace('<br /><ul', '<ul ', $Text);

		Hook::callAll('bbcode', $text);

		return trim($text);
	}

	/**
	 * @brief Strips the "abstract" tag from the provided text
	 *
	 * @param string $text The text with BBCode
	 * @return string The same text - but without "abstract" element
	 */
	public static function stripAbstract($text)
	{
		$text = preg_replace("/[\s|\n]*\[abstract\].*?\[\/abstract\][\s|\n]*/ism", '', $text);
		$text = preg_replace("/[\s|\n]*\[abstract=.*?\].*?\[\/abstract][\s|\n]*/ism", '', $text);

		return $text;
	}

	/**
	 * @brief Returns the value of the "abstract" element
	 *
	 * @param string $text The text that maybe contains the element
	 * @param string $addon The addon for which the abstract is meant for
	 * @return string The abstract
	 */
	public static function getAbstract($text, $addon = "")
	{
		$abstract = "";
		$abstracts = [];
		$addon = strtolower($addon);

		if (preg_match_all("/\[abstract=(.*?)\](.*?)\[\/abstract\]/ism", $text, $results, PREG_SET_ORDER)) {
			foreach ($results AS $result) {
				$abstracts[strtolower($result[1])] = $result[2];
			}
		}

		if (isset($abstracts[$addon])) {
			$abstract = $abstracts[$addon];
		}

		if ($abstract == "" && preg_match("/\[abstract\](.*?)\[\/abstract\]/ism", $text, $result)) {
			$abstract = $result[1];
		}

		return $abstract;
	}

	/**
	 * @brief Callback function to replace a Friendica style mention in a mention for Diaspora
	 *
	 * @param array $match Matching values for the callback
	 *                     [1] = Mention type (! or @)
	 *                     [2] = Name
	 *                     [3] = Address
	 * @return string Replaced mention
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	private static function bbCodeMention2DiasporaCallback($match)
	{
		$contact = Contact::getDetailsByURL($match[3]);

		if (empty($contact['addr'])) {
			$contact = Probe::uri($match[3]);
		}

		if (empty($contact['addr'])) {
			return $match[0];
		}

		$mention = $match[1] . '{' . $match[2] . '; ' . $contact['addr'] . '}';
		return $mention;
	}

	/**
	 * @brief Converts a BBCode text into Markdown
	 *
	 * This function converts a BBCode item body to be sent to Markdown-enabled
	 * systems like Diaspora and Libertree
	 *
	 * @param string $text
	 * @param bool   $for_diaspora Diaspora requires more changes than Libertree
	 * @return string
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 */
	public static function toMarkdown($text, $for_diaspora = true)
	{
		$a = self::getApp();

		$original_text = $text;

		// Since Diaspora is creating a summary for links, this function removes them before posting
		if ($for_diaspora) {
			$text = self::removeShareInformation($text);
		}

		/**
		 * Transform #tags, strip off the [url] and replace spaces with underscore
		 */
		$url_search_string = "^\[\]";
		$text = preg_replace_callback("/#\[url\=([$url_search_string]*)\](.*?)\[\/url\]/i",
			function ($matches) {
				return '#' . str_replace(' ', '_', $matches[2]);
			},
			$text
		);

		// Converting images with size parameters to simple images. Markdown doesn't know it.
		$text = preg_replace("/\[img\=([0-9]*)x([0-9]*)\](.*?)\[\/img\]/ism", '[img]$3[/img]', $text);

		// Convert it to HTML - don't try oembed
		if ($for_diaspora) {
			$text = self::convert($text, false, 3);

			// Add all tags that maybe were removed
			if (preg_match_all("/#\[url\=([$url_search_string]*)\](.*?)\[\/url\]/ism", $original_text, $tags)) {
				$tagline = "";
				foreach ($tags[2] as $tag) {
					$tag = html_entity_decode($tag, ENT_QUOTES, 'UTF-8');
					if (!strpos(html_entity_decode($text, ENT_QUOTES, 'UTF-8'), '#' . $tag)) {
						$tagline .= '#' . $tag . ' ';
					}
				}
				$text = $text . " " . $tagline;
			}
		} else {
			$text = self::convert($text, false, 4);
		}

		// mask some special HTML chars from conversation to markdown
		$text = str_replace(['&lt;', '&gt;', '&amp;'], ['&_lt_;', '&_gt_;', '&_amp_;'], $text);

		// If a link is followed by a quote then there should be a newline before it
		// Maybe we should make this newline at every time before a quote.
		$text = str_replace(["</a><blockquote>"], ["</a><br><blockquote>"], $text);

		$stamp1 = microtime(true);

		// Now convert HTML to Markdown
		$text = HTML::toMarkdown($text);

		// unmask the special chars back to HTML
		$text = str_replace(['&\_lt\_;', '&\_gt\_;', '&\_amp\_;'], ['&lt;', '&gt;', '&amp;'], $text);

		$a->getProfiler()->saveTimestamp($stamp1, "parser", System::callstack());

		// Libertree has a problem with escaped hashtags.
		$text = str_replace(['\#'], ['#'], $text);

		// Remove any leading or trailing whitespace, as this will mess up
		// the Diaspora signature verification and cause the item to disappear
		$text = trim($text);

		if ($for_diaspora) {
			$url_search_string = "^\[\]";
			$text = preg_replace_callback(
				"/([@!])\[(.*?)\]\(([$url_search_string]*?)\)/ism",
				['self', 'bbCodeMention2DiasporaCallback'],
				$text
			);
		}

		Hook::callAll('bb2diaspora', $text);

		return $text;
	}

	/**
     * @brief Pull out all #hashtags and @person tags from $string.
     *
     * We also get @person@domain.com - which would make
     * the regex quite complicated as tags can also
     * end a sentence. So we'll run through our results
     * and strip the period from any tags which end with one.
     * Returns array of tags found, or empty array.
     *
     * @param string $string Post content
     * 
     * @return array List of tag and person names
     */
    public static function getTags($string)
    {
        $ret = [];

        // Convert hashtag links to hashtags
        $string = preg_replace('/#\[url\=([^\[\]]*)\](.*?)\[\/url\]/ism', '#$2', $string);

        // ignore anything in a code block
        $string = preg_replace('/\[code.*?\].*?\[\/code\]/sm', '', $string);

        // Force line feeds at bbtags
        $string = str_replace(['[', ']'], ["\n[", "]\n"], $string);

        // ignore anything in a bbtag
        $string = preg_replace('/\[(.*?)\]/sm', '', $string);

        // Match full names against @tags including the space between first and last
        // We will look these up afterward to see if they are full names or not recognisable.

        if (preg_match_all('/(@[^ \x0D\x0A,:?]+ [^ \x0D\x0A@,:?]+)([ \x0D\x0A@,:?]|$)/', $string, $matches)) {
            foreach ($matches[1] as $match) {
                if (strstr($match, ']')) {
                    // we might be inside a bbcode color tag - leave it alone
                    continue;
                }

                if (substr($match, -1, 1) === '.') {
                    $ret[] = substr($match, 0, -1);
                } else {
                    $ret[] = $match;
                }
            }
        }

        // Otherwise pull out single word tags. These can be @nickname, @first_last
        // and #hash tags.

        if (preg_match_all('/([!#@][^\^ \x0D\x0A,;:?]+)([ \x0D\x0A,;:?]|$)/', $string, $matches)) {
            foreach ($matches[1] as $match) {
                if (strstr($match, ']')) {
                    // we might be inside a bbcode color tag - leave it alone
                    continue;
                }
                if (substr($match, -1, 1) === '.') {
                    $match = substr($match,0,-1);
                }
                // ignore strictly numeric tags like #1
                if ((strpos($match, '#') === 0) && ctype_digit(substr($match, 1))) {
                    continue;
                }
                // try not to catch url fragments
                if (strpos($string, $match) && preg_match('/[a-zA-z0-9\/]/', substr($string, strpos($string, $match) - 1, 1))) {
                    continue;
                }
                $ret[] = $match;
            }
        }

        return $ret;
    }
}
