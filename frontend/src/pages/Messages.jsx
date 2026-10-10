import { useState, useEffect, useCallback, useRef } from 'react';
import { useSearchParams, Link } from 'react-router-dom';
import { motion } from 'framer-motion';
import toast from 'react-hot-toast';
import {
  HiChatBubbleLeftRight,
  HiPaperAirplane,
  HiHomeModern,
  HiCheck,
  HiArrowRight
} from 'react-icons/hi2';
import {
  getConversations, getConversation, sendMessage,
} from '../services/messageService';
import { getProperty } from '../services/propertyService';
import { useAuth } from '../contexts/AuthContext';
import { Button } from '../components/ui/button';
import { Skeleton } from '../components/ui/skeleton';

const formatTime = (iso) =>
  iso
    ? new Date(iso).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })
    : '';

const formatDay = (iso) => {
  if (!iso) return '';
  const date = new Date(iso);
  const today = new Date();
  const yesterday = new Date(today);
  yesterday.setDate(today.getDate() - 1);

  if (date.toDateString() === today.toDateString()) return 'Today';
  if (date.toDateString() === yesterday.toDateString()) return 'Yesterday';

  return date.toLocaleDateString('en-GB', { day: '2-digit', month: 'short' });
};

const errorText = (error, fallback) => error?.response?.data?.message || fallback;

// Motion Variants
const containerVariants = {
  hidden: { opacity: 0 },
  visible: {
    opacity: 1,
    transition: {
      staggerChildren: 0.05,
      delayChildren: 0.05,
    },
  },
};

const listItemVariants = {
  hidden: { opacity: 0, x: -12 },
  visible: {
    opacity: 1,
    x: 0,
    transition: { type: 'spring', stiffness: 300, damping: 26 },
  },
};

/**
 * One inbox for both sides of the conversation.
 *
 * A tenant arrives here from a property page with ?property=<id> and writes the
 * first message of a thread; an owner only ever replies inside an existing one.
 */
const Messages = () => {
  const { user } = useAuth();
  const [searchParams, setSearchParams] = useSearchParams();

  const requestedPropertyId = searchParams.get('property');
  const activeId = searchParams.get('conversation');

  const [conversations, setConversations] = useState([]);
  const [loadingList, setLoadingList] = useState(true);
  const [thread, setThread] = useState(null);
  const [loadingThread, setLoadingThread] = useState(false);
  const [messages, setMessages] = useState([]);
  const [content, setContent] = useState('');
  const [sending, setSending] = useState(false);
  const [startingProperty, setStartingProperty] = useState(null);
  const [loadingProperty, setLoadingProperty] = useState(false);

  const bottomRef = useRef(null);

  const loadConversations = useCallback(async () => {
    try {
      const response = await getConversations();
      setConversations(response.data.data || []);
    } catch {
      // The inbox keeps whatever it already shows rather than flashing empty.
    } finally {
      setLoadingList(false);
    }
  }, []);

  useEffect(() => {
    loadConversations();
  }, [loadConversations]);

  // A property page sent the tenant here to start writing, so the property is
  // resolved once and its owner names the recipient.
  useEffect(() => {
    if (!requestedPropertyId || !user) {
      setStartingProperty(null);
      return;
    }

    let cancelled = false;
    setLoadingProperty(true);

    getProperty(requestedPropertyId)
      .then((response) => {
        if (cancelled) return;
        const property = response.data?.data || response.data;
        setStartingProperty({ id: property.id, title: property.title, owner: property.owner });
      })
      .catch(() => {
        if (!cancelled) {
          toast.error('That property could not be loaded.');
          setSearchParams({}, { replace: true });
        }
      })
      .finally(() => {
        if (!cancelled) setLoadingProperty(false);
      });

    return () => {
      cancelled = true;
    };
  }, [requestedPropertyId, user, setSearchParams]);

  useEffect(() => {
    if (!activeId) {
      setThread(null);
      setMessages([]);
      return;
    }

    let cancelled = false;
    setLoadingThread(true);

    getConversation(activeId)
      .then((response) => {
        if (cancelled) return;
        setThread(response.data.conversation);
        setMessages(response.data.messages || []);
      })
      .catch((error) => {
        if (cancelled) return;
        setThread(null);
        setMessages([]);
        toast.error(errorText(error, 'This conversation could not be opened.'));
        setSearchParams({}, { replace: true });
      })
      .finally(() => {
        if (!cancelled) setLoadingThread(false);
      });

    return () => {
      cancelled = true;
    };
  }, [activeId, setSearchParams]);

  useEffect(() => {
    bottomRef.current?.scrollIntoView({ block: 'end' });
  }, [messages]);

  // Opening a thread marks it read, so the list is refreshed to clear its badge.
  useEffect(() => {
    if (activeId) loadConversations();
  }, [activeId, loadConversations]);

  const selectConversation = (id) => setSearchParams({ conversation: String(id) });

  const handleSend = async (event) => {
    event.preventDefault();
    const body = content.trim();
    if (!body || sending) return;

    setSending(true);
    try {
      const response = await sendMessage(
        thread
          ? { conversation_id: thread.id, content: body }
          : {
              receiver_id: startingProperty.owner?.id,
              property_id: startingProperty.id,
              content: body,
            }
      );

      setMessages((previous) => [...previous, response.data.message]);
      setContent('');
      loadConversations();

      // A brand new thread becomes the active one so replies keep the context.
      if (!thread && response.data.conversation_id) {
        setSearchParams(
          { conversation: String(response.data.conversation_id) },
          { replace: true }
        );
      }
    } catch (error) {
      toast.error(errorText(error, 'Your message could not be sent.'));
    } finally {
      setSending(false);
    }
  };

  const canWrite = Boolean(thread) || (Boolean(startingProperty) && Boolean(startingProperty.owner));

  return (
    <motion.div
      initial={{ opacity: 0, y: 12 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.35, ease: 'easeOut' }}
      className="p-4 sm:p-6 lg:p-8 max-w-7xl mx-auto space-y-6 antialiased selection:bg-orange-500 selection:text-white"
    >
      {/* Header Section */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-2 border-b border-slate-200/60">
        <div className="relative">
          <div className="flex items-center gap-3">
            {/* Orange Gradient Accent Bar */}
            <div className="h-7 w-1.5 rounded-full bg-gradient-to-b from-orange-500 to-amber-500 shadow-sm" />

            <div className="flex items-center gap-3 flex-wrap">
              <h1 className="text-2xl sm:text-3xl font-extrabold tracking-tight text-slate-900 font-display">
                Messages
              </h1>

              {/* Count Badge with Live Pulse Dot */}
              <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-orange-50 border border-orange-200/80 text-orange-700 shadow-2xs">
                <span className="relative flex h-2 w-2">
                  <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-orange-400 opacity-75"></span>
                  <span className="relative inline-flex rounded-full h-2 w-2 bg-orange-500"></span>
                </span>
                {conversations.length} {conversations.length === 1 ? 'Conversation' : 'Conversations'}
              </span>
            </div>
          </div>

          <p className="text-sm text-slate-500 mt-1.5 ml-4.5 font-normal leading-relaxed">
            {user?.role === 'owner'
              ? 'Questions tenants asked about your properties.'
              : 'Talk directly with the owners of the properties you are interested in.'}
          </p>
        </div>
      </div>

      <div className="grid lg:grid-cols-[320px_1fr] gap-6 items-start">
        {/* Threads */}
        <div className="rounded-2xl border border-slate-200/80 bg-white shadow-xs hover:shadow-lg hover:shadow-slate-200/50 transition-all duration-200 overflow-hidden">
          <div className="flex items-center gap-2.5 px-5 py-4 border-b border-slate-100">
            <div className="w-9 h-9 rounded-xl bg-orange-50 border border-orange-100 text-orange-600 flex items-center justify-center shrink-0">
              <HiChatBubbleLeftRight className="w-4 h-4" />
            </div>
            <h2 className="text-sm font-bold uppercase tracking-wider text-slate-500">
              Conversations
            </h2>
          </div>

          {loadingList ? (
            <div className="p-4 space-y-3">
              {[...Array(3)].map((_, i) => (
                <Skeleton key={i} className="h-16 w-full rounded-xl" />
              ))}
            </div>
          ) : conversations.length === 0 ? (
            <div className="px-5 py-12 text-center">
              <div className="w-14 h-14 bg-orange-50 rounded-2xl border border-orange-100 text-orange-600 flex items-center justify-center mx-auto mb-3">
                <HiChatBubbleLeftRight className="w-7 h-7" />
              </div>
              <p className="text-sm font-bold text-slate-900 tracking-tight">No conversation yet</p>
              <p className="text-xs text-slate-500 mt-1.5 leading-relaxed">
                {user?.role === 'tenant'
                  ? 'Open a property to write to its owner.'
                  : 'Threads will appear here as tenants reach out.'}
              </p>
            </div>
          ) : (
            <motion.ul
              variants={containerVariants}
              initial="hidden"
              animate="visible"
              className="max-h-[560px] overflow-y-auto divide-y divide-slate-100"
            >
              {conversations.map((conversation) => {
                const active = conversation.id === Number(activeId);
                return (
                  <motion.li key={conversation.id} variants={listItemVariants}>
                    <button
                      onClick={() => selectConversation(conversation.id)}
                      className={`w-full text-left px-4 py-3.5 transition-colors duration-200 cursor-pointer ${
                        active
                          ? 'bg-orange-50/70 border-l-2 border-orange-500'
                          : 'hover:bg-slate-50 border-l-2 border-transparent'
                      }`}
                    >
                      <div className="flex items-start justify-between gap-2">
                        <p
                          className={`font-bold text-sm truncate transition-colors duration-200 ${
                            active ? 'text-orange-700' : 'text-slate-900'
                          }`}
                        >
                          {conversation.counterpart?.full_name || 'Unknown'}
                        </p>
                        <span className="text-[11px] text-slate-400 shrink-0 font-medium">
                          {formatDay(conversation.last_message_at)}
                        </span>
                      </div>
                      <p className="text-xs text-slate-500 truncate mt-1 flex items-center gap-1">
                        <HiHomeModern className="w-3.5 h-3.5 shrink-0 text-slate-400" />
                        {conversation.property?.title || 'Property removed'}
                      </p>
                      <div className="flex items-center justify-between gap-2 mt-1.5">
                        <p className="text-xs text-slate-400 truncate">
                          {conversation.last_message?.content || 'No message yet'}
                        </p>
                        {conversation.unread_count > 0 && (
                          <span className="shrink-0 inline-flex items-center justify-center min-w-5 h-5 px-1.5 rounded-full bg-orange-500 text-white text-[10px] font-bold shadow-xs">
                            {conversation.unread_count}
                          </span>
                        )}
                      </div>
                    </button>
                  </motion.li>
                );
              })}
            </motion.ul>
          )}
        </div>

        {/* Thread */}
        <div className="rounded-2xl border border-slate-200/80 bg-white shadow-xs hover:shadow-lg hover:shadow-slate-200/50 transition-all duration-200 flex flex-col min-h-[560px] overflow-hidden">
          {loadingThread ? (
            <div className="flex-1 p-6 space-y-4">
              <Skeleton className="h-12 w-2/3 rounded-2xl" />
              <Skeleton className="h-12 w-1/2 ml-auto rounded-2xl" />
              <Skeleton className="h-12 w-3/5 rounded-2xl" />
            </div>
          ) : thread ? (
            <>
              <div className="px-5 py-4 border-b border-slate-100 flex items-center justify-between gap-3 bg-slate-50/50">
                <div className="min-w-0">
                  <p className="font-bold text-slate-900 truncate tracking-tight">
                    {thread.counterpart?.full_name}
                  </p>
                  <p className="text-xs text-slate-500 truncate flex items-center gap-1.5 mt-0.5">
                    <Link
                      to={`/properties/${thread.property?.id}`}
                      className="inline-flex items-center gap-1 hover:text-orange-600 transition-colors duration-200 no-underline hover:no-underline"
                    >
                      <HiHomeModern className="w-3.5 h-3.5 text-slate-400" />
                      {thread.property?.title}
                    </Link>
                    {thread.reservation && (
                      <span className="shrink-0 rounded-lg bg-white border border-slate-200/80 px-2 py-0.5 text-[10px] font-semibold text-slate-600 font-mono">
                        {thread.reservation.reference}
                      </span>
                    )}
                  </p>
                </div>
                <span className="shrink-0 px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider bg-orange-50 border border-orange-200/80 text-orange-700">
                  {thread.counterpart?.role}
                </span>
              </div>

              <div className="flex-1 px-5 py-4 space-y-3 overflow-y-auto max-h-[420px] bg-slate-50/30">
                {messages.map((message) => {
                  const mine = message.sender_id === user?.id;
                  return (
                    <motion.div
                      key={message.id}
                      initial={{ opacity: 0, y: 8, scale: 0.98 }}
                      animate={{ opacity: 1, y: 0, scale: 1 }}
                      transition={{ duration: 0.22, ease: 'easeOut' }}
                      className={`flex ${mine ? 'justify-end' : 'justify-start'}`}
                    >
                      <div
                        className={`max-w-[75%] rounded-2xl px-4 py-2.5 shadow-xs border ${
                          mine
                            ? 'bg-slate-900 text-white border-slate-800 rounded-br-md'
                            : 'bg-white text-slate-800 border-slate-200/80 rounded-bl-md'
                        }`}
                      >
                        <p className="text-sm leading-relaxed whitespace-pre-line break-words">
                          {message.content}
                        </p>
                        <p className="mt-1 flex items-center justify-end gap-1 text-[10px] text-slate-400">
                          {formatTime(message.sent_at)}
                          {mine && message.is_read && (
                            <HiCheck className="w-3 h-3 text-orange-400" />
                          )}
                        </p>
                      </div>
                    </motion.div>
                  );
                })}
                <div ref={bottomRef} />
              </div>

              <form
                onSubmit={handleSend}
                className="px-5 py-4 border-t border-slate-100 flex items-end gap-2 bg-slate-50/50"
              >
                <textarea
                  value={content}
                  onChange={(event) => setContent(event.target.value)}
                  onKeyDown={(event) => {
                    if (event.key === 'Enter' && !event.shiftKey) {
                      event.preventDefault();
                      handleSend(event);
                    }
                  }}
                  rows={1}
                  maxLength={5000}
                  placeholder="Write a message..."
                  className="flex-1 resize-none rounded-xl border border-slate-200/90 bg-white px-4 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 shadow-xs focus:outline-none focus:border-orange-500 focus:ring-2 focus:ring-orange-500/15 transition-all max-h-32 min-h-[42px]"
                />
                <Button
                  type="submit"
                  disabled={sending || !content.trim()}
                  aria-label="Send message"
                  className="h-[42px] px-4 bg-slate-900 hover:bg-slate-800 text-white font-semibold text-sm rounded-xl shadow-sm hover:shadow-md active:scale-[0.98] transition-all duration-200 border border-slate-800 no-underline hover:no-underline cursor-pointer"
                >
                  <HiPaperAirplane className="w-4 h-4 text-orange-500 stroke-[2.5]" />
                </Button>
              </form>
            </>
          ) : startingProperty ? (
            loadingProperty ? (
              <div className="flex-1 p-6 space-y-3">
                <Skeleton className="h-6 w-1/2" />
                <Skeleton className="h-24 w-full rounded-xl" />
              </div>
            ) : (
              <>
                <div className="px-5 py-4 border-b border-slate-100 bg-slate-50/50">
                  <p className="font-bold text-slate-900 tracking-tight">
                    {startingProperty.owner?.full_name || startingProperty.owner?.name}
                  </p>
                  <p className="text-xs text-slate-500 mt-0.5">{startingProperty.title}</p>
                </div>
                <div className="flex-1 px-5 py-6 bg-slate-50/30">
                  <p className="text-sm text-slate-500 leading-relaxed">
                    This is the start of your conversation about this property. Ask anything you need
                    to know before you reserve it.
                  </p>
                </div>
                <form
                  onSubmit={handleSend}
                  className="px-5 py-4 border-t border-slate-100 flex items-end gap-2 bg-slate-50/50"
                >
                  <textarea
                    value={content}
                    onChange={(event) => setContent(event.target.value)}
                    rows={1}
                    maxLength={5000}
                    placeholder="Write your first message..."
                    className="flex-1 resize-none rounded-xl border border-slate-200/90 bg-white px-4 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 shadow-xs focus:outline-none focus:border-orange-500 focus:ring-2 focus:ring-orange-500/15 transition-all max-h-32 min-h-[42px]"
                  />
                  <Button
                    type="submit"
                    disabled={sending || !content.trim() || !canWrite}
                    aria-label="Send message"
                    className="h-[42px] px-4 bg-slate-900 hover:bg-slate-800 text-white font-semibold text-sm rounded-xl shadow-sm hover:shadow-md active:scale-[0.98] transition-all duration-200 border border-slate-800 no-underline hover:no-underline cursor-pointer"
                  >
                    <HiPaperAirplane className="w-4 h-4 text-orange-500 stroke-[2.5]" />
                  </Button>
                </form>
              </>
            )
          ) : (
            <div className="flex-1 flex flex-col items-center justify-center text-center px-6">
              <div className="w-16 h-16 bg-orange-50 rounded-2xl border border-orange-100 text-orange-600 flex items-center justify-center mb-4">
                <HiChatBubbleLeftRight className="w-8 h-8" />
              </div>
              <p className="font-bold text-slate-900 tracking-tight">Select a conversation</p>
              <p className="text-sm text-slate-500 mt-1.5 max-w-xs leading-relaxed">
                {user?.role === 'tenant'
                  ? 'Pick a thread, or open a property to write to its owner.'
                  : 'Pick a thread to read and answer it.'}
              </p>
              {user?.role === 'tenant' && (
                <Link
                  to="/properties"
                  className="mt-5 inline-flex items-center gap-2 h-10 px-4 rounded-xl bg-slate-900 hover:bg-slate-800 text-white text-sm font-semibold shadow-sm hover:shadow-md active:scale-[0.98] transition-all duration-200 no-underline hover:no-underline cursor-pointer"
                >
                  Browse Properties
                  <HiArrowRight className="w-4 h-4 text-orange-500 stroke-[2.5]" />
                </Link>
              )}
            </div>
          )}
        </div>
      </div>
    </motion.div>
  );
};

export default Messages;