<x-layouts::app :title="$conversation->title">
    <livewire:ai-kit::components.chat.conversation
        :conversation="$conversation"
        :initial-message="$initialMessage"
        agent="App\Ai\Agents\Assistant"
    />
</x-layouts::app>
