<?php
/**
 * Invitations to Groups
 */
namespace Minds\Plugin\Groups\Core;

use Minds\Core\Di\Di;
use Minds\Core\Events\Dispatcher;
use Minds\Core\Queue\Client as QueueClient;
use Minds\Entities\Factory as EntitiesFactory;
use Minds\Entities\User as User;
use Minds\Plugin\Groups\Entities\Group as GroupEntity;
use Minds\Plugin\Groups\Core\Membership as CoreMembership;

class Invitations
{
    protected $relDB;
    protected $group;

    /**
     * Constructor
     * @param GroupEntity $group
     */
    public function __construct(GroupEntity $group, $db = null)
    {
        $this->group = $group;
        $this->relDB = $db ?: Di::_()->get('Database\Cassandra\Relationships');
    }

    /**
     * Fetch the group invitations
     * @return array
     */
    public function getInvitations()
    {
        $this->relDB->setGuid($this->group->getGuid());

        return $this->relDB->get('group:invited', [
            'inverse' => true
        ]);
    }

    /**
     * Checks a GUID array for invitation status
     * @param  array   $users
     * @return boolean
     */
    public function isInvitedBatch(array $users = [])
    {
        if (!$users) {
            return [];
        }

        $invited_guids = $this->getInvitations();
        $result = [];

        foreach ($users as $user) {
            $result[$user] = in_array($user, $invited_guids);
        }

        return $result;
    }

    /**
     * Checks invitation status
     * @param  mixed   $invitee
     * @return boolean
     */
    public function isInvited($invitee)
    {
        if (!$invitee) {
            return false;
        }

        $invitee_guid = is_object($invitee) ? $invitee->guid : $invitee;
        $this->relDB->setGuid($invitee_guid);

        return $this->relDB->check('group:invited', $this->group->getGuid());
    }

    /**
     * Invites a user to the group
     * @param  mixed   $invitee
     * @param  mixed   $from
     * @return boolean
     */
    public function invite($invitee, $from)
    {
        if (!$invitee) {
            return false;
        }

        if (!$from) {
            return false;
        }

        $from = !is_object($from) ? EntityFactory::build($from) : $from;
        $canInvite = $this->userCanInvite($from, $invitee);

        if (!$canInvite) {
            return false;
        }

        $invitee_guid = is_object($invitee) ? $invitee->guid : $invitee;
        // TODO: [emi] Check if the user blocked this group from sending invites
        $this->relDB->setGuid($invitee_guid);

        $invited = $this->relDB->create('group:invited', $this->group->getGuid());

        Dispatcher::trigger('notification', 'all', [
            'to' => [ $invitee_guid ],
            'notification_view' => 'group_invite',
            'params' => [
                'group' => $this->group->export(),
                'user' => $from->username
            ]
        ]);

        return $invited;
    }

    /**
     * Destroys a user invitation to the group
     * @param  mixed   $invitee
     * @param  mixed   $from
     * @return boolean
     */
    public function uninvite($invitee, $from)
    {
        if (!$invitee) {
            return false;
        }

        if (!$from) {
            return false;
        }

        $from = !is_object($from) ? EntityFactory::build($from) : $from;
        $canInvite = $this->userCanInvite($from, $invitee);

        if (!$canInvite) {
            return false;
        }

        return $this->removeInviteFromIndex($invitee);
    }

    /**
     * Accepts an invitation to the group
     * @param  mixed   $invitee
     * @return boolean
     */
    public function accept($invitee)
    {
        if (!$invitee) {
            return false;
        }

        $this->removeInviteFromIndex($invitee);
        return $this->group->join($invitee, [ 'force' => true ]);
    }

    /**
     * Declines an invitation to the group
     * @param  mixed   $invitee
     * @return boolean
     */
    public function decline($invitee)
    {
        if (!$invitee) {
            return false;
        }

        $this->removeInviteFromIndex($invitee);
        return true;
    }

    /**
     * Checks if the user can invite to the group. It'll optionally check if it can invite a certain user.
     * @param  mixed   $user
     * @param  mixed   $invitee Optional.
     * @return boolean
     */
    public function userCanInvite($user, $invitee = null)
    {
        $user = !is_object($user) ? EntityFactory::build($user) : $user;
        $invitee = $invitee && !is_object($invitee) ? EntityFactory::build($invitee) : $invitee;

        if ($user->isAdmin()) {
            return true;
        } elseif ($this->group->isPublic() && $this->group->isMember($user)) {
            return $invitee ? $this->userHasSubscriber($user, $invitee) : true;
        } elseif (!$this->group->isPublic() && $this->group->canEdit($user)) {
            return $invitee ? $this->userHasSubscriber($user, $invitee) : true;
        }

        return false;
    }

    /**
     * Checks if a user has a certain subscriber
     * @param  User   $user
     * @param  User   $subscriber
     * @return boolean
     */
    protected function userHasSubscriber(User $user, User $subscriber)
    {
        // TODO: [emi] Ask Mark about a 'friendsof' replacement (or create a DI entry)
        $db = new \Minds\Core\Data\Call('friendsof');
        $row = $db->getRow($user->getGuid(), [ 'limit' => 1, 'offset' => (string) $subscriber->getGuid() ]);

        return $row && isset($row[(string) $subscriber->getGuid()]);
    }

    /**
     * Shrotcut function to remove a GUID from the "group:invited" index.
     * @param  mixed $invitee
     * @return boolean
     */
    protected function removeInviteFromIndex($invitee)
    {
        $invitee_guid = is_object($invitee) ? $invitee->guid : $invitee;
        $this->relDB->setGuid($invitee_guid);

        return $this->relDB->remove('group:invited', $this->group->getGuid());
    }
}
