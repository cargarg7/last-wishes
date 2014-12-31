<?php

namespace Lw\Application\Service;

use Ddd\Application\EventStore;
use JMS\Serializer\SerializerBuilder;
use Lw\Domain\Model\Event\StoredEvent;
use Lw\Domain\PublishedMessageTracker;

class NotificationService
{
    const EXCHANGE_NAME = 'lastwill';

    private $serializer;
    private $eventStore;
    private $publishedMessageTracker;
    private $messageProducer;

    public function __construct(
        EventStore $anEventStore,
        PublishedMessageTracker $aPublishedMessageTracker,
        MessageProducer $aMessageProducer
    )
    {
        $this->eventStore = $anEventStore;
        $this->publishedMessageTracker = $aPublishedMessageTracker;
        $this->messageProducer = $aMessageProducer;
    }

    /**
     * @return int
     */
    public function publishNotifications($exchangeName)
    {
        $publishedMessageTracker = $this->publishedMessageTracker();
        $notifications = $this->listUnpublishedNotifications(
            $publishedMessageTracker->mostRecentPublishedMessageId($exchangeName)
        );

        if (!$notifications) {
            return 0;
        }

        $messageProducer = $this->messageProducer();
        try {
            $publishedMessages = 0;
            $lastPublishedNotification = null;
            foreach ($notifications as $notification) {
                $lastPublishedNotification = $this->publish($exchangeName, $notification, $messageProducer);
                $publishedMessages++;
            }
        } finally {
            $this->trackMostRecentPublishedMessage($publishedMessageTracker, $exchangeName, $lastPublishedNotification);
            $messageProducer->close($exchangeName);
        }

        return $publishedMessages;
    }

    /**
     * @return PublishedMessageTracker
     */
    protected function publishedMessageTracker()
    {
        return $this->publishedMessageTracker;
    }

    /**
     * @param $mostRecentPublishedMessageId
     * @return StoredEvent[]
     */
    private function listUnpublishedNotifications($mostRecentPublishedMessageId)
    {
        $storeEvents = $this->eventStore()->allStoredEventsSince($mostRecentPublishedMessageId);

        // Vaughn Vernon converts StoredEvents into another objects: Notification
        // Why? Why? Why? Why?

        return $storeEvents;
    }

    /**
     * @return EventStore
     */
    protected function eventStore()
    {
        return $this->eventStore;
    }

    private function messageProducer()
    {
        return $this->messageProducer;
    }

    private function publish($exchangeName, StoredEvent $notification, MessageProducer $messageProducer)
    {
        $messageProducer->send(
            $exchangeName,
            $this->serializer()->serialize($notification, 'json'),
            $notification->typeName(),
            $notification->eventId(),
            $notification->occurredOn()
        );

        return $notification;
    }

    /**
     * @return \JMS\Serializer\Serializer
     */
    private function serializer()
    {
        if (null === $this->serializer) {
            $this->serializer = SerializerBuilder::create()->build();
        }

        return $this->serializer;
    }

    private function trackMostRecentPublishedMessage(PublishedMessageTracker $publishedMessageTracker, $exchangeName, $notification)
    {
        $publishedMessageTracker->trackMostRecentPublishedMessage($exchangeName, $notification);
    }
}
