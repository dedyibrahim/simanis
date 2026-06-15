<?php

namespace App\Http\Controllers;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;

class GoogleCalendarController extends ApiController
{
    public function getEvents(Request $request)
    {
        try {
            $accessToken = $request->post('token_google');
            $calendarId = 'primary';

            $curl = curl_init();
            curl_setopt_array($curl, [
                CURLOPT_URL => "https://www.googleapis.com/calendar/v3/calendars/{$calendarId}/events",
                CURLOPT_HTTPHEADER => [
                'Authorization: Bearer '.$accessToken,
                'Accept: application/json',
              ],
              CURLOPT_RETURNTRANSFER => true,
            ]);

            $response = curl_exec($curl);
            curl_close($curl);

            $data = json_decode($response, true);

            for ($i = 0; $i < count($data['items']); ++$i) {
                if (isset($data['items'][$i]['location'])) {
                    if ($data['items'][$i]['location'] == 'Di Luar') {
                        $color = 'primary';
                    } else {
                        $color = 'secondary';
                    }
                } else {
                    $color = 'error';
                }

                if (isset($data['items'][$i]['start']['dateTime'])) {
                    $timestamp = strtotime($data['items'][$i]['start']['dateTime']);
                    $start = date('Y-m-d H:i:s', $timestamp);
                } else {
                    $timestamp = strtotime($data['items'][$i]['created']);
                    $start = date('Y-m-d H:i:s', $timestamp);
                }

                if (isset($data['items'][$i]['end']['dateTime'])) {
                    $timestamp = strtotime($data['items'][$i]['end']['dateTime']);
                    $end = date('Y-m-d H:i:s', $timestamp);
                } else {
                    $timestamp = strtotime($data['items'][$i]['created']);
                    $end = date('Y-m-d H:i:s', $timestamp);
                }

                if (isset($data['items'][$i]['summary'])) {
                    $summari = $data['items'][$i]['summary'];
                } else {
                    $summari = null;
                }

                $data2[] = [
                 'id' => $data['items'][$i]['id'],
                 'name' => $summari,
                 'start' => $start,
                 'end' => $end,
                 'color' => $color,
                 'creator' => $data['items'][$i]['creator']['email'],
                 'timed' => true,
                ];
            }

            $response = [
                'status' => true,
                'message' => $data,
                'data' => $data2,
            ];

            return response($response, 200);
        } catch (Exception $e) {
            echo print_r($e);
        }
    }

    public function setEvents(Request $request)
    {
        $email = User::select('email')->get()->toArray();

        $request->validate([
            'name' => ['required'],
            'jenis' => ['required'],
            'start' => ['required'],
            'end' => ['required'],
        ]);

        $accessToken = $request->post('token_google');
        $calendarId = 'primary';
        $apiEndpoint = "https://www.googleapis.com/calendar/v3/calendars/$calendarId/events";

        $startconvert = Carbon::createFromFormat('Y-m-d\TH:i', $request->post('start'));

        $endconvert = Carbon::createFromFormat('Y-m-d\TH:i', $request->post('end'));

        $eventData = [
            'summary' => $request->post('name'),
            'location' => $request->post('jenis'),
            'description' => '-',
            'start' => [
                'dateTime' => $startconvert,
                'timeZone' => 'Asia/Jakarta',
            ],
            'end' => [
                'dateTime' => $endconvert,
                'timeZone' => 'Asia/Jakarta',
            ],
            'recurrence' => [
                'RRULE:FREQ=DAILY;COUNT=2',
            ],
            'attendees' => $email,
            'reminders' => [
                'useDefault' => false,
                'overrides' => [
                    ['method' => 'email', 'minutes' => 24 * 60],
                    ['method' => 'popup', 'minutes' => 10],
                ],
            ],
            'notifications' => $email,
        ];
        $jsonData = json_encode($eventData);

        $curl = curl_init();

        curl_setopt($curl, CURLOPT_URL, $apiEndpoint);

        curl_setopt($curl, CURLOPT_POST, true);

        curl_setopt($curl, CURLOPT_POSTFIELDS, $jsonData);

        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer '.$accessToken,
        ]);

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($curl);

        if ($response === false) {
            $error = curl_error($curl);
        } else {
            $responseData = json_decode($response, true);
            $response2 = [
                'status' => true,
                'message' => $responseData,
                'data' => $responseData,
            ];

            return response($response2, 200);
        }

        curl_close($curl);
    }

    public function deleteEvents(Request $request)
    {
        $accessToken = $request->post('token_google'); // Ganti dengan token akses OAuth Google Anda
        $eventId = $request->post('id'); // Ganti dengan ID acara yang ingin Anda hapus
        $calendarId = 'primary';

        $url = "https://www.googleapis.com/calendar/v3/calendars/$calendarId/events/$eventId";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer '.$accessToken,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode == 204) {
            $response2 = [
                'status' => true,
                'message' => 'BerhasilHapus Calender',
                'data' => $response,
            ];

            return response($response2, 200);
        } else {
            $response2 = [
                'status' => true,
                'message' => 'Gagal Hapus Calender',
                'data' => $response,
            ];

            return response($response2, 500);
        }

        curl_close($ch);
    }
}
