<?php
/**
 * @version            3.0.0
 * @package            Joomla
 * @subpackage         Membership Pro
 * @author             Billplz Sdn. Bhd.
 */
// no direct access
defined('_JEXEC') or die;

class os_billplz extends MPFPayment
{

    protected $api_key;
    protected $collection_id;
    protected $x_signature;

    /**
     * Constructor functions, init some parameter
     *
     * @param \Joomla\Registry\Registry $params
     * @param array                     $config
     */
    public function __construct($params, $config = array())
    {
        parent::__construct($params, $config);

        $this->api_key = trim($params->get('api_key'));
        $this->collection_id = trim($params->get('collection_id'));
        $this->x_signature = trim($params->get('x_signature'));
    }

    /**
     * Process Payment
     *
     * @param OSMembershipTableSubscriber $row
     * @param array                       $data
     */
    public function processPayment($row, $data)
    {
        $app    = JFactory::getApplication();
        $Itemid = $app->input->getInt('Itemid');

        $siteUrl = JUri::base();

        include 'lib/API.php';
        include 'lib/Connect.php';
        $connect = (new BillplzConnect($this->api_key))->detectMode();
        $billplz = new BillplzAPI($connect);

        $parameter = array(
            'collection_id' => $this->collection_id,
            'email'=> $row->email,
            'name' => substr("{$row->first_name} {$row->last_name}", 0, 255),
            'amount' => strval(round($data['amount'], 2) * 100),
            'mobile' => trim($row->phone),
            'callback_url' => $siteUrl . 'index.php?option=com_osmembership&task=payment_confirm&payment_method=os_billplz',
            'description' => substr(trim($data['item_name']), 0, 200)
        );

        $optional = array(
            'redirect_url' => $parameter['callback_url'],
        );

        list($rheader, $rbody) = $billplz->toArray($billplz->createBill($parameter, $optional));

        if ($rheader !== 200) {
            throw new Exception('Failure to create bill');
            exit;
        }

        // Store Bill ID before redirecting to Billplz
        $row->receiver_email = "{$rbody['id']}|$Itemid";
        $row->store();

        header("Location: {$rbody['url']}");
    }

    /**
     * Verify payment
     *
     * @return bool
     */
    public function verifyPayment()
    {
        include 'lib/Connect.php';

        try {
            $data = BillplzConnect::getXSignature($this->x_signature);
        } catch (\Exception $e) {
            throw new Exception('Failed to verify data integrity');
            exit;
        }

        $this->notificationData = $data;
        $this->logGatewayData();

        $bill_id = $data['id'];
        $paid = $data['paid'];
        $type = $data['type'] === 'redirect' ? true : false;

        $Itemid = $this->getItemid($bill_id);
        $siteUrl = JUri::base();

        if (!$paid && $type) {
            header('Location: '. $siteUrl . 'index.php?option=com_osmembership&view=cancel&id=' . $row->id . '&Itemid=' . $Itemid);
            exit;
        } elseif (!$paid) {
            exit;
        }

        // Check and make sure the transaction is only processed one time
        if (OSMembershipHelper::isTransactionProcessed($bill_id)) {
            if ($type) {
                header('Location: '. $this->getReturnUrl($Itemid));
            }
            exit;
        }

        $id = $this->getId($bill_id);

        $row = JTable::getInstance('OsMembership', 'Subscriber');

        if (!$row->load($id)) {
            exit("Couldn't load data");
        }

        // If the subsctiption is active, it was processed before, return false
        if ($row->published) {
            if ($type) {
                header('Location: '. $this->getReturnUrl($Itemid));
            }
            exit;
        }

        // This will final the process, set subscription status to active, trigger onMembershipActive event, sending emails to subscriber and admin...
        $this->onPaymentSuccess($row, $bill_id);

        if ($type) {
            header('Location: '. $this->getReturnUrl($Itemid));
        }
        exit;
    }

    public function getSupportedCurrencies()
    {
        return array('MYR');
    }

    private function getId($bill_id)
    {
        $db    = JFactory::getDbo();
        $query = $db->getQuery(true);
        $query->select('id')
            ->from('#__osmembership_subscribers')
            ->where('receiver_email LIKE ' . $db->quote($bill_id.'%'));
        $db->setQuery($query);
        return (int) $db->loadResult();
    }

    private function getItemid($bill_id)
    {
        $db    = JFactory::getDbo();
        $query = $db->getQuery(true);
        $query->select('receiver_email')
            ->from('#__osmembership_subscribers')
            ->where('receiver_email LIKE ' . $db->quote($bill_id.'%'));
        $db->setQuery($query);
        $result = $db->loadResult();
        $result_array = explode('|', $result);
        return $result_array[1];
    }

    /**
     * Get SEF return URL after processing payment
     *
     * @param int $Itemid
     *
     * @return string
     */
    protected function getReturnUrl($Itemid)
    {
        $rootURL    = rtrim(JUri::root(), '/');
        $subpathURL = JUri::root(true);

        if (!empty($subpathURL) && ($subpathURL != '/')) {
            $rootURL = substr($rootURL, 0, -1 * strlen($subpathURL));
        }

        return $rootURL . JRoute::_(OSMembershipHelperRoute::getViewRoute('complete', $Itemid), false);
    }
}
