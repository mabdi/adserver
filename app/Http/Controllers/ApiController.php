<?php

namespace App\Http\Controllers;

use Adshares\Esc\Esc;

use App\Banner;
use App\Campaign;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;

/**
 * API commands that are used to communicate and share informations between adserver isntances
 */
class ApiController extends Controller
{
    public function adsharesInventoryList(Request $request)
    {
        // TODO : convert 2 laravel - follow up for data structure

        foreach (Campaign::with('Banners')->get() as $i => $campaign) {
            $campaigns[$i] = $campaign->toArray();
            // TODO: move out to configuration
            $campaigns[$i]['adshares_address'] = Esc::normalizeAddress('0001-00000001-8B4E');
        }
        return Response::json(['campaigns' => $campaigns], 200, array(), JSON_PRETTY_PRINT);
    }

    /**
     * @Route("")
     */
    public function adsharesTransactionReport(Request $request, $tx_id, $pay_to)
    {
        // TODO : convert 2 laravel

        $em = $this->getDoctrine()->getManager();
        assert($em instanceof \Doctrine\ORM\EntityManager);

        $paymentId = Payment::getRepository($em)->findOneBy(['tx_id' => $tx_id]);

        if (!$paymentId) {
            $response = null;
        } else {
            $response = [];

            $events = $em->createQuery("SELECT e.publisher_event_id, e.paid_amount, e.timestamp FROM Adshares\Entity\EventLog e WHERE e.payment_id = :payment_id AND e.pay_to = :pay_to")
          ->iterate(['payment_id' => $paymentId, 'pay_to' => $pay_to], Query::HYDRATE_SCALAR);

            $minTime = PHP_INT_MAX;
            $maxTime = PHP_INT_MIN;
            foreach ($events as $event) {
//                 $response[] = $event[0];
                $response[$pay_to]['events'][$event[0]['publisher_event_id']] = $event[0]['paid_amount'];
                $minTime = min($event[0]['timestamp'], $minTime);
                $maxTime = max($event[0]['timestamp'], $maxTime);
            }

            if ($maxTime != PHP_INT_MIN && $minTime != PHP_INT_MAX) {
                $response[$pay_to]['time_start'] = $minTime;
                $response[$pay_to]['time_end'] = $maxTime;
            }
        }
        $x = new Response();
        $x->headers->set("Content-Type", "text/json");
        $x->setContent(json_encode($response));
        return $x;
    }
}
