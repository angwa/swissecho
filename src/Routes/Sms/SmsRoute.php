<?php

namespace Tekkenking\Swissecho\Routes\Sms;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Tekkenking\Swissecho\Routes\BaseRoute;
use Tekkenking\Swissecho\SwissechoException;

class SmsRoute extends BaseRoute
{

    protected $defaultPlace;

    /**
     * @param $notifiable
     * @param Notification $notification
     * @return SmsRoute
     * @throws SwissechoException
     */
    public function send($notifiable, Notification $notification): static
    {
        $this->msgBuilder = $notification->viaSms($notifiable);
        $this->msgBuilder->to($this->prepareTo($notifiable));
        $this->msgBuilder->sender($this->prepareSender($notifiable));
        $this->pushToGateway($notifiable);
        return $this;
    }

    /**
     * @param $routeBuilder
     * @return $this
     * @throws SwissechoException
     */
    public function directSend($routeBuilder): static
    {
        $this->msgBuilder = $routeBuilder;
        $this->msgBuilder->sender($this->prepareSender());
        $this->pushToGateway($this->mockedNotifiable);
        return $this;
    }

    protected function getDefaultPlace()
    {
        $this->defaultPlace = array_key_first($this->config['routes_options'][$this->getRoute()]['places']);
    }

    /**
     * @param $notifiable
     * @return mixed
     * @throws SwissechoException
     */
    protected function prepareTo($notifiable): mixed
    {
        if(!$this->msgBuilder->to) {

            // THIS IS FROM TEMPUSERS TABLE
            if (isset($notifiable->phone)) {
                return $notifiable->phone;
            }

            // THIS IS FROM THE CURRENT MODEL
            if (method_exists($notifiable, 'routeNotificationPhone')) {
                return $notifiable->routeNotificationPhone($notifiable);
            }
        }

        return $this->msgBuilder->to;

        //throw new SwissechoException('Notification: Invalid sms phone number');
    }

    /**
     * Get the alphanumeric sender.
     *
     * @param $notifiable
     * @return mixed
     */
    protected function prepareSender($notifiable = null): mixed
    {
        if(!$this->msgBuilder->sender ) {

            if ($notifiable
                && method_exists($notifiable, 'routeNotificationSmsSender')) {
                return $notifiable->routeNotificationSmsSender($notifiable);
            }

            $gatewaySender = $this->gatewaySender();
            if($gatewaySender) {
                return $gatewaySender;
            }
        }

        return $this->msgBuilder->sender;
    }

    protected function pushToGateway($notifiable = null)
    {
        if(!$this->msgBuilder->to) {
            throw new SwissechoException('Notification: Invalid sms phone number');
        }

        $this->getDefaultPlace();
        $gatewayConfig = $this->gatewayConfig();
        $place = $this->defaultPlace;

        $this->msgBuilder->gateway = $this->gateway;
        $this->msgBuilder->phonecode = $this->config['routes_options']['sms']['places'][$place]['phonecode'];

        if($notifiable && method_exists($notifiable, 'routeNotificationSmsCountry')) {
            $place = strtolower($notifiable->routeNotificationSmsCountry($notifiable));

            if($place) {
                if(isset($this->config['routes_options']['sms']['places'][$place])) {
                    $gatewayFromPlaceArr = $this->config['routes_options']['sms']['places'][$place];

                    //Load the gateway by place
                    //dd($gatewayFromPlaceArr);
                    $gatewayConfig = $this->config['routes_options']['sms']['gateway_options'][$gatewayFromPlaceArr['gateway']];



                    $this->msgBuilder->gateway = $gatewayFromPlaceArr['gateway'];
                    $this->msgBuilder->phonecode = $gatewayFromPlaceArr['phonecode'];
                }else {
                    Log::alert('SMSECHO: SMS place does not exist: '.$place, []);
                }

            }
        }

        $this->msgBuilder->place = $place;
        $this->msgBuilder->to = $this->prepTo($this->msgBuilder->to, $this->msgBuilder->phonecode);

        if($this->config['live'] == false) {
            $this->mockSend($gatewayConfig, $this->msgBuilder);
        } else {
            $gatewayClass = $gatewayConfig['class'];
            (new $gatewayClass($gatewayConfig, $this->msgBuilder->get()))->boot();
        }

    }

    private function prepTo($to, $phonecode): array
    {
        if(!is_array($to)) {
            $to = explode(',', $to);
        }

        $toArr = [];
        foreach ($to ?? [] as $number) {
            $toArr[] = add_country_code($number, $phonecode);
        }

        return $toArr;
    }

}
