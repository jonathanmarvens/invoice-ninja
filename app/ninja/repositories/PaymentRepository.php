<?php namespace ninja\repositories;

use Payment;
use Credit;
use Invoice;
use Client;
use Utils;

class PaymentRepository
{
	public function find($clientPublicId = null, $filter = null)
	{
        $query = \DB::table('payments')
                    ->join('clients', 'clients.id', '=','payments.client_id')
                    ->join('invoices', 'invoices.id', '=','payments.invoice_id')
                    ->join('contacts', 'contacts.client_id', '=', 'clients.id')
                    ->leftJoin('payment_types', 'payment_types.id', '=', 'payments.payment_type_id')
                    ->where('payments.account_id', '=', \Auth::user()->account_id)
                    ->where('clients.deleted_at', '=', null)
                    ->where('contacts.is_primary', '=', true)   
                    ->select('payments.public_id', 'payments.transaction_reference', 'clients.name as client_name', 'clients.public_id as client_public_id', 'payments.amount', 'payments.payment_date', 'invoices.public_id as invoice_public_id', 'invoices.invoice_number', 'clients.currency_id', 'contacts.first_name', 'contacts.last_name', 'contacts.email', 'payment_types.name as payment_type');        

        if (!\Session::get('show_trash'))
        {
            $query->where('payments.deleted_at', '=', null);
        }

        if ($clientPublicId) 
        {
            $query->where('clients.public_id', '=', $clientPublicId);
        }

        if ($filter)
        {
            $query->where(function($query) use ($filter)
            {
                $query->where('clients.name', 'like', '%'.$filter.'%');
            });
        }

        return $query;
	}

    public function getErrors($input)
    {
        $rules = array(
            'client' => 'required',
            'invoice' => 'required',  
            'amount' => 'required|positive'
        );
        
        if ($input['payment_type_id'] == PAYMENT_TYPE_CREDIT)
        {
            $rules['payment_type_id'] = 'has_credit:' . $input['client'] . ',' . $input['amount'];
        }

        $validator = \Validator::make($input, $rules);

        if ($validator->fails())
        {
            return $validator;
        }

        return false;
    }

	public function save($publicId = null, $input)
	{
        if ($publicId) 
        {
            $payment = Payment::scope($publicId)->firstOrFail();
        } 
        else 
        {
            $payment = Payment::createNew();
        }

        $paymentTypeId = $input['payment_type_id'] ? $input['payment_type_id'] : null;
        $amount = Utils::parseFloat($input['amount']);

        if ($paymentTypeId == PAYMENT_TYPE_CREDIT)
        {
            $credits = Credit::scope()->where('balance', '>', 0)->orderBy('created_at')->get();            
            $applied = 0;

            foreach ($credits as $credit)
            {
                $applied += $credit->apply($amount);

                if ($applied >= $amount)
                {
                    break;
                }
            }
        }

        $payment->client_id = Client::getPrivateId($input['client']);
        $payment->invoice_id = isset($input['invoice']) && $input['invoice'] != "-1" ? Invoice::getPrivateId($input['invoice']) : null;
        $payment->payment_type_id = $paymentTypeId;
        $payment->payment_date = Utils::toSqlDate($input['payment_date']);
        $payment->amount = $amount;
        $payment->save();
	
		return $payment;		
	}

	public function bulk($ids, $action)
	{
        if (!$ids)
        {
            return 0;
        }

        $payments = Payment::scope($ids)->get();

        foreach ($payments as $payment) 
        {            
            if ($action == 'delete') 
            {
                $payment->is_deleted = true;
                $payment->save();
            } 

            $payment->delete();
        }
	
		return count($payments);
	}
}