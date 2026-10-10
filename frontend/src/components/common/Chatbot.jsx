import { useState, useRef, useEffect } from 'react';
import { AnimatePresence, motion } from 'framer-motion';
import { sendMessage } from '../../services/chatbotService';
import { HiOutlineChat, HiX, HiPaperAirplane } from 'react-icons/hi';

const Chatbot = () => {
  const [isOpen, setIsOpen] = useState(false);
  const [messages, setMessages] = useState([
    { role: 'bot', text: 'Hello! I am the SAKANIA AI assistant. I can help you with properties and search, reservations, payments, contracts, messages, notifications, your account, owner and tenant features, and troubleshooting SAKANIA problems. What do you need?' },
  ]);
  const [input, setInput] = useState('');
  const [typing, setTyping] = useState(false);
  const [conversationId, setConversationId] = useState(null);
  const messagesEndRef = useRef(null);
  const inputRef = useRef(null);

  const scrollToBottom = () => {
    messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
  };

  useEffect(() => {
    scrollToBottom();
  }, [messages, typing]);

  useEffect(() => {
    if (isOpen) {
      setTimeout(() => inputRef.current?.focus(), 200);
    }
  }, [isOpen]);

  const handleSend = async () => {
    const text = input.trim();
    if (!text) return;

    setInput('');
    setMessages((prev) => [...prev, { role: 'user', text }]);
    setTyping(true);

    try {
      const res = await sendMessage(text, conversationId);
      const data = res.data || {};
      if (data.conversation_id) setConversationId(data.conversation_id);
      setMessages((prev) => [...prev, { role: 'bot', text: data.reply || 'Sorry, I could not understand that. Please try again.' }]);
    } catch (err) {
      // A 500 from the assistant still carries a user-facing message; a 422
      // carries the validation text. Anything else falls back to a fixed line.
      const body = err?.response?.data;
      const failure = body?.reply
        || (err?.response?.status === 422 && body?.message)
        || 'Sorry, something went wrong on my side. Please try again in a moment.';
      setMessages((prev) => [...prev, { role: 'bot', text: failure }]);
    } finally {
      setTyping(false);
    }
  };

  const handleKeyDown = (e) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      handleSend();
    }
  };

  return (
    <>
      <AnimatePresence>
        {isOpen && (
          <motion.div
            initial={{ opacity: 0, scale: 0.95, y: 16 }}
            animate={{ opacity: 1, scale: 1, y: 0 }}
            exit={{ opacity: 0, scale: 0.95, y: 16 }}
            transition={{ duration: 0.25, ease: [0.16, 1, 0.3, 1] }}
            style={{ transformOrigin: 'bottom right' }}
            className="fixed bottom-20 right-4 sm:right-6 z-50 w-[360px] max-w-[calc(100vw-2rem)] max-h-[calc(100dvh-7rem)] bg-white rounded-2xl shadow-2xl shadow-gray-900/15 border border-gray-200/70 flex flex-col overflow-hidden"
          >
            <div className="sticky top-0 z-10 bg-brand-gradient px-4 py-3.5 flex items-center justify-between shrink-0 overflow-hidden">
              <div className="absolute inset-0 bg-[radial-gradient(circle_at_top_right,rgba(255,255,255,0.25),transparent_60%)]" />
              <div className="flex items-center gap-3 relative">
                <div className="w-9 h-9 rounded-xl bg-white/20 flex items-center justify-center backdrop-blur-sm">
                  <HiOutlineChat className="w-4.5 h-4.5 text-white" />
                </div>
                <div>
                  <h3 className="text-sm font-semibold text-white">SAKANIA Assistant</h3>
                  <p className="text-[10px] text-orange-100 flex items-center gap-1.5">
                    <span className="w-1.5 h-1.5 rounded-full bg-emerald-400 inline-block" />
                    AI-powered support
                  </p>
                </div>
              </div>
              <button onClick={() => setIsOpen(false)} className="relative p-1.5 hover:bg-white/20 rounded-lg transition-colors">
                <HiX className="w-5 h-5 text-white" />
              </button>
            </div>

            <div className="flex-1 overflow-y-auto p-4 space-y-3 bg-gray-50/50">
              {messages.map((msg, i) => (
                <motion.div
                  key={i}
                  initial={{ opacity: 0, y: 8 }}
                  animate={{ opacity: 1, y: 0 }}
                  transition={{ duration: 0.25, ease: [0.16, 1, 0.3, 1] }}
                  className={`flex ${msg.role === 'user' ? 'justify-end' : 'justify-start'}`}
                >
                  <div className={`max-w-[80%] px-3.5 py-2.5 rounded-2xl text-sm leading-relaxed ${
                    msg.role === 'user'
                      ? 'bg-brand-gradient text-white rounded-br-md shadow-md shadow-brand/20'
                      : 'bg-white text-gray-800 rounded-bl-md border border-gray-200/80 shadow-sm'
                  } whitespace-pre-wrap break-words`}>
                    {msg.text}
                  </div>
                </motion.div>
              ))}
              {typing && (
                <div className="flex justify-start">
                  <div className="bg-white border border-gray-200/80 px-4 py-3 rounded-2xl rounded-bl-md shadow-sm">
                    <div className="flex gap-1">
                      <span className="w-2 h-2 bg-brand/60 rounded-full animate-bounce" style={{ animationDelay: '0ms' }}></span>
                      <span className="w-2 h-2 bg-brand/60 rounded-full animate-bounce" style={{ animationDelay: '150ms' }}></span>
                      <span className="w-2 h-2 bg-brand/60 rounded-full animate-bounce" style={{ animationDelay: '300ms' }}></span>
                    </div>
                  </div>
                </div>
              )}
              <div ref={messagesEndRef} />
            </div>

            <div className="p-3 border-t border-gray-100 shrink-0 bg-white">
              <div className="flex items-center gap-2">
                <input
                  ref={inputRef}
                  type="text"
                  value={input}
                  onChange={(e) => setInput(e.target.value)}
                  onKeyDown={handleKeyDown}
                  placeholder="Type your message..."
                  className="flex-1 px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-xl text-sm focus:outline-none focus:bg-white focus:border-brand/40 focus:ring-4 focus:ring-brand/10 transition-all"
                />
                <button
                  onClick={handleSend}
                  disabled={!input.trim() || typing}
                  className="p-2.5 bg-brand-gradient text-white rounded-xl shadow-lg shadow-brand/25 hover:shadow-xl transition-all disabled:opacity-50 disabled:cursor-not-allowed disabled:shadow-none shrink-0 active:scale-95"
                >
                  <HiPaperAirplane className="w-4 h-4" />
                </button>
              </div>
            </div>
          </motion.div>
        )}
      </AnimatePresence>

      <button
        onClick={() => setIsOpen(!isOpen)}
        className="fixed bottom-4 right-4 sm:right-6 z-50 w-14 h-14 bg-brand-gradient text-white rounded-full shadow-xl shadow-brand/30 flex items-center justify-center hover:scale-110 active:scale-95 transition-transform group"
        aria-label="Chat assistant"
      >
        {isOpen ? (
          <HiX className="w-6 h-6" />
        ) : (
          <>
            <HiOutlineChat className="w-6 h-6" />
            <span className="absolute -top-1 -right-1 w-4 h-4 bg-emerald-500 rounded-full border-2 border-white animate-pulse"></span>
            <span className="absolute inset-0 rounded-full bg-brand/30 animate-ping -z-10" />
          </>
        )}
      </button>
    </>
  );
};

export default Chatbot;
