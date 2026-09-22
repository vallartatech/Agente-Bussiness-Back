<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    public function index(Request $request, $trabajoId)
    {
        $query = \App\Models\TrabajoChat::with('sender:id,name,role_id')->where('trabajo_id', $trabajoId);

        if ($request->filled('canal')) {
            $query->where('canal', $request->canal);
        }

        $chats = $query->orderBy('created_at', 'asc')->get();

        // Cargar role
        $chats->each(function($chat) {
            if ($chat->sender) {
                $chat->sender->load('role:id,name');
            }
        });
        return response()->json($chats);
    }

    public function destroy($trabajoId)
    {
        \App\Models\TrabajoChat::where('trabajo_id', $trabajoId)->delete();
        return response()->json(['message' => 'Chat borrado exitosamente']);
    }

    public function store(Request $request, $trabajoId)
    {
        $request->validate([
            'message' => 'required|string',
            'canal' => 'nullable|string',
            'is_quote' => 'boolean',
            'quote_amount' => 'numeric|nullable'
        ]);

        $canal = $request->input('canal', 'cliente_admin');

        $chat = \App\Models\TrabajoChat::create([
            'trabajo_id' => $trabajoId,
            'canal' => $canal,
            'sender_id' => $request->user()->id,
            'message' => $request->message,
            'is_quote' => $request->boolean('is_quote', false),
            'quote_amount' => $request->quote_amount
        ]);

        $chat->load('sender:id,name,role_id');
        if ($chat->sender) {
            $chat->sender->load('role:id,name');
        }

        // Transmitir mensaje por WebSockets
        broadcast(new \App\Events\ChatMessageSent($chat));

        // Notificar a las otras partes involucradas
        $trabajo = \App\Models\Trabajo::with('negocio')->findOrFail($trabajoId);
        $senderId = $request->user()->id;
        
        $usersToNotify = [];
        
        // 1. Técnico
        if ($trabajo->trabajador_id && $trabajo->trabajador_id != $senderId) {
            $tecnico = \App\Models\Trabajador::find($trabajo->trabajador_id);
            if ($tecnico && $tecnico->user_id != $senderId) {
                $usersToNotify[] = $tecnico->user_id;
            }
        }
        
        // 2. Subgerente (Encargado del negocio)
        if ($trabajo->negocio && $trabajo->negocio->encargado_id && $trabajo->negocio->encargado_id != $senderId) {
            $usersToNotify[] = $trabajo->negocio->encargado_id;
        }

        // 3. Admin / Gerente General (quien haya creado el ecosistema o sea admin)
        if ($trabajo->admin_autonomo_id && $trabajo->admin_autonomo_id != $senderId) {
            $usersToNotify[] = $trabajo->admin_autonomo_id;
        }

        foreach ($usersToNotify as $uid) {
            $notif = \App\Models\Notificacion::create([
                'user_id' => $uid,
                'titulo' => '💬 Nuevo mensaje en el chat',
                'mensaje' => 'Nuevo mensaje de ' . $request->user()->name . ' en la solicitud #' . $trabajoId,
                'enlace' => '/menu/trabajo-detalle/' . $trabajoId . '?tab=cotizacion',
                'leido' => false
            ]);

            broadcast(new \App\Events\NotificationSent($notif));
        }

        return response()->json($chat);
    }

    public function quoteAction(Request $request, $trabajoId)
    {
        $request->validate([
            'action' => 'required|in:accept,reject',
            'canal' => 'nullable|string',
            'reason' => 'nullable|string'
        ]);

        $trabajo = \App\Models\Trabajo::findOrFail($trabajoId);
        $user = $request->user();

        if ($request->action === 'accept') {
            $trabajo->estado = 'Trabajo';
            $trabajo->save();
            $message = 'Ha aceptado la propuesta. ¡El trabajo ha iniciado!';
        } else {
            $message = 'Ha rechazado la propuesta. Motivo: ' . $request->reason;
        }

        $canal = $request->input('canal', 'cliente_admin');

        $chat = \App\Models\TrabajoChat::create([
            'trabajo_id' => $trabajoId,
            'canal' => $canal,
            'sender_id' => $user->id,
            'message' => $message,
            'is_quote' => false,
            'quote_amount' => null
        ]);

        $chat->load('sender:id,name,role_id');
        if ($chat->sender) {
            $chat->sender->load('role:id,name');
        }

        broadcast(new \App\Events\ChatMessageSent($chat));

        return response()->json([
            'message' => 'Acción registrada con éxito',
            'chat' => $chat,
            'trabajo' => $trabajo
        ]);
    }
}
