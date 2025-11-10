<?php

namespace App\Console\Commands;

use App\Models\Payment;
use Illuminate\Console\Command;

class ExpirePayments extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'payments:expire';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Expire pending payments that have passed their expiry time';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Checking for expired payments...');

        $expiredPayments = Payment::where('status', 'pending')
            ->whereNotNull('expiry_time')
            ->where('expiry_time', '<', now())
            ->get();

        if ($expiredPayments->isEmpty()) {
            $this->info('No expired payments found.');
            return 0;
        }

        $count = 0;
        foreach ($expiredPayments as $payment) {
            $payment->update(['status' => 'expire']);
            
            // Update order status
            $payment->order->update(['status' => 'expired']);

            $this->line("Payment #{$payment->id} (Order #{$payment->order->id}) marked as expired");
            $count++;
        }

        $this->info("Successfully expired {$count} payment(s).");
        return 0;
    }
}