import { useState, useEffect, useRef } from 'react';
import { MainLayout } from '../../components/layout/MainLayout';
import { Card } from '../../components/common/Card';
import { Button } from '../../components/common/Button';
import { Input } from '../../components/common/Input';
import { Loading } from '../../components/common/Loading';
import {
  getWhatsAppSettings,
  createWhatsAppLink,
  linkExistingWhatsApp,
  getWhatsAppAccountInfo,
  checkWhatsAppConnection,
  confirmWhatsAppLink,
  relinkWhatsAppAccount,
  disconnectWhatsApp,
  updateWhatsAppSettings,
  testSendWhatsApp,
  previewWhatsAppRecap,
  sendWhatsAppRecap,
} from '../../api/whatsapp.api';
import { usePageTitle } from '../../hooks/usePageTitle';
import { MessageCircle, Settings, Send, Trash2, RefreshCw, CheckCircle, XCircle, Eye } from 'lucide-react';
import toast from 'react-hot-toast';

export const WhatsAppSettings = () => {
  usePageTitle('WhatsApp Gateway');

  const [loading, setLoading] = useState(true);
  const [settings, setSettings] = useState(null);
  const [linkingState, setLinkingState] = useState({
    step: 'idle', // idle | entering_secret | showing_qr | connected
    linkMethod: 'qr', // qr | existing
    qrImageUrl: null,
    token: null,
    apiSecret: '',
    uniqueId: '', // For link existing
    expectedUniqueId: null, // For relink verification
  });
  const [configForm, setConfigForm] = useState({
    whatsapp_recipient_phone: '',
    whatsapp_recap_time: '22:00',
    whatsapp_recap_enabled: false,
  });
  const [submitting, setSubmitting] = useState(false);
  const [testing, setTesting] = useState(false);
  const [previewing, setPreviewing] = useState(false);
  const [previewMessage, setPreviewMessage] = useState('');
  const [showPreviewModal, setShowPreviewModal] = useState(false);
  const pollIntervalRef = useRef(null);

  useEffect(() => {
    fetchSettings();
    return () => {
      // Cleanup polling on unmount
      if (pollIntervalRef.current) {
        clearInterval(pollIntervalRef.current);
      }
    };
  }, []);

  const fetchSettings = async () => {
    try {
      setLoading(true);
      const response = await getWhatsAppSettings();

      if (response.success) {
        console.log('[WhatsApp] Settings loaded:', response.data);
        setSettings(response.data);
        setConfigForm({
          whatsapp_recipient_phone: response.data.recipient_phone || '',
          whatsapp_recap_time: response.data.recap_time || '22:00',
          whatsapp_recap_enabled: response.data.recap_enabled || false,
        });
        setLinkingState({
          step: response.data.connected ? 'connected' : 'idle',
          qrImageUrl: null,
          token: null,
          apiSecret: '',
        });
      }
    } catch (error) {
      console.error('Error fetching WhatsApp settings:', error);
      toast.error('Gagal mengambil pengaturan WhatsApp');
    } finally {
      setLoading(false);
    }
  };

  const startLinking = (method = 'qr') => {
    setLinkingState({ ...linkingState, step: 'entering_secret', linkMethod: method });
  };

  const handleLinkExisting = async () => {
    if (!linkingState.apiSecret || linkingState.apiSecret.length < 10) {
      toast.error('API Secret harus minimal 10 karakter');
      return;
    }

    if (!linkingState.uniqueId || linkingState.uniqueId.length < 20) {
      toast.error('Unique ID harus minimal 20 karakter');
      return;
    }

    try {
      setSubmitting(true);
      const response = await linkExistingWhatsApp(linkingState.apiSecret, linkingState.uniqueId);

      if (response.success && response.data) {
        // Update settings directly
        setSettings(response.data);
        setConfigForm({
          whatsapp_recipient_phone: response.data.recipient_phone || '',
          whatsapp_recap_time: response.data.recap_time || '22:00',
          whatsapp_recap_enabled: response.data.recap_enabled || false,
        });

        toast.success('WhatsApp berhasil terhubung!');
        setLinkingState({ step: 'idle', linkMethod: 'qr', qrImageUrl: null, token: null, apiSecret: '', uniqueId: '', expectedUniqueId: null });
      }
    } catch (error) {
      const message = error.response?.data?.message || 'Gagal menghubungkan WhatsApp';
      toast.error(message);
    } finally {
      setSubmitting(false);
    }
  };

  const handleCreateLink = async () => {
    if (!linkingState.apiSecret || linkingState.apiSecret.length < 10) {
      toast.error('API Secret harus minimal 10 karakter');
      return;
    }

    try {
      setSubmitting(true);
      const response = await createWhatsAppLink(linkingState.apiSecret);

      if (response.success) {
        setLinkingState({
          ...linkingState,
          step: 'showing_qr',
          qrImageUrl: response.data.qr_image_url,
          token: response.data.token,
        });
        toast.success('QR Code berhasil dibuat. Scan dengan WhatsApp Anda.');
        startPolling(response.data.token);
      }
    } catch (error) {
      const message = error.response?.data?.message || 'Gagal membuat QR Code';
      toast.error(message);
    } finally {
      setSubmitting(false);
    }
  };

  const startPolling = (token, isRelink = false, expectedUniqueId = null) => {
    // Clear any existing interval
    if (pollIntervalRef.current) {
      clearInterval(pollIntervalRef.current);
    }

    if (isRelink || !token) {
      // For relink (no token), use checkConnection polling (checks WA Gateway + auto-updates DB)
      console.log('[WhatsApp] Starting relink polling (checking WA Gateway)...');
      console.log('[WhatsApp] Expected unique_id:', expectedUniqueId);

      pollIntervalRef.current = setInterval(async () => {
        try {
          console.log('[WhatsApp] Checking WA Gateway connection status...');
          const response = await checkWhatsAppConnection();
          console.log('[WhatsApp] Check connection response:', response);

          if (response.success && response.data) {
            // IMPORTANT: Verify this is the correct account (not another connected account)
            if (expectedUniqueId && response.data.unique_id !== expectedUniqueId) {
              console.log('[WhatsApp] Detected different account, still waiting for correct one...');
              console.log('[WhatsApp] Expected:', expectedUniqueId, 'Got:', response.data.unique_id);
              return; // Continue polling
            }

            console.log('[WhatsApp] Correct account reconnected!', response.data);
            clearInterval(pollIntervalRef.current);

            // Fetch fresh settings to get all data
            const settingsResponse = await getWhatsAppSettings();
            if (settingsResponse.success) {
              setSettings(settingsResponse.data);
              setConfigForm({
                whatsapp_recipient_phone: settingsResponse.data.recipient_phone || '',
                whatsapp_recap_time: settingsResponse.data.recap_time || '22:00',
                whatsapp_recap_enabled: settingsResponse.data.recap_enabled || false,
              });
            }

            toast.success('WhatsApp berhasil terhubung kembali!');
            setLinkingState({ step: 'idle', qrImageUrl: null, token: null, apiSecret: '', expectedUniqueId: null });
          } else {
            console.log('[WhatsApp] Still waiting for QR scan...');
          }
        } catch (error) {
          console.error('[WhatsApp] Polling error:', error);
        }
      }, 3000);
    } else {
      // For initial link (with token), use token-based polling
      console.log('[WhatsApp] Starting polling with token:', token);

      pollIntervalRef.current = setInterval(async () => {
        try {
          console.log('[WhatsApp] Checking for QR scan with token...');
          const response = await getWhatsAppAccountInfo(token);
          console.log('[WhatsApp] Poll response:', response);

          if (response.success && response.data) {
            console.log('[WhatsApp] QR scanned! Account info:', response.data);
            clearInterval(pollIntervalRef.current);

            // Confirm the link to save account info to database
            const confirmResponse = await confirmWhatsAppLink(token);

            if (confirmResponse.success && confirmResponse.data) {
              console.log('[WhatsApp] Confirmed and updated settings:', confirmResponse.data);
              // Update settings state directly from confirm response
              setSettings(confirmResponse.data);
              // Also update config form with latest data
              setConfigForm({
                whatsapp_recipient_phone: confirmResponse.data.recipient_phone || '',
                whatsapp_recap_time: confirmResponse.data.recap_time || '22:00',
                whatsapp_recap_enabled: confirmResponse.data.recap_enabled || false,
              });
            }

            toast.success('WhatsApp berhasil terhubung!');
            setLinkingState({ step: 'idle', qrImageUrl: null, token: null, apiSecret: '' });
          } else {
            console.log('[WhatsApp] Still waiting for QR scan...');
          }
        } catch (error) {
          console.error('[WhatsApp] Polling error:', error);
        }
      }, 3000);
    }

    // Auto-stop after 5 minutes (QR expiration)
    setTimeout(() => {
      if (pollIntervalRef.current) {
        clearInterval(pollIntervalRef.current);
        console.log('[WhatsApp] QR Code expired (5 minutes timeout)');
        toast.error('QR Code expired. Silakan coba lagi.');
        setLinkingState({ step: 'idle', qrImageUrl: null, token: null, apiSecret: '' });
      }
    }, 300000);
  };

  const handleRelink = async () => {
    try {
      setSubmitting(true);
      const response = await relinkWhatsAppAccount();

      if (response.success) {
        // Store expected unique_id for verification during polling
        const expectedUniqueId = settings.account_unique_id;

        setLinkingState({
          ...linkingState,
          step: 'showing_qr',
          qrImageUrl: response.data.qr_image_url,
          token: response.data.token,
          expectedUniqueId: expectedUniqueId, // Store for verification
        });
        toast.success('QR Code relink berhasil dibuat. Scan dengan WhatsApp Anda.');
        // Start polling for relink with expected unique_id for verification
        startPolling(response.data.token, true, expectedUniqueId);
      }
    } catch (error) {
      const message = error.response?.data?.message || 'Gagal membuat QR Code relink';
      toast.error(message);
    } finally {
      setSubmitting(false);
    }
  };

  const handleDisconnect = async () => {
    if (!confirm('Apakah Anda yakin ingin memutuskan koneksi WhatsApp?')) {
      return;
    }

    try {
      setSubmitting(true);
      const response = await disconnectWhatsApp();

      if (response.success) {
        toast.success('WhatsApp berhasil diputuskan');
        fetchSettings();
      }
    } catch (error) {
      const message = error.response?.data?.message || 'Gagal memutuskan WhatsApp';
      toast.error(message);
    } finally {
      setSubmitting(false);
    }
  };

  const handleUpdateSettings = async (e) => {
    e.preventDefault();

    try {
      setSubmitting(true);
      const response = await updateWhatsAppSettings(configForm);

      if (response.success) {
        toast.success('Pengaturan WhatsApp berhasil diperbarui');
        fetchSettings();
      }
    } catch (error) {
      const message = error.response?.data?.message || 'Gagal memperbarui pengaturan';
      toast.error(message);
    } finally {
      setSubmitting(false);
    }
  };

  const handleTestSend = async () => {
    try {
      setTesting(true);
      const response = await testSendWhatsApp();

      if (response.success) {
        toast.success('Pesan test berhasil dikirim!');
      }
    } catch (error) {
      const message = error.response?.data?.message || 'Gagal mengirim pesan test';
      toast.error(message);
    } finally {
      setTesting(false);
    }
  };

  const handlePreviewRecap = async () => {
    try {
      setPreviewing(true);
      const response = await previewWhatsAppRecap();

      if (response.success) {
        setPreviewMessage(response.data.message);
        setShowPreviewModal(true);
      }
    } catch (error) {
      const message = error.response?.data?.message || 'Gagal membuat preview';
      toast.error(message);
    } finally {
      setPreviewing(false);
    }
  };

  const handleSendRecap = async () => {
    if (!confirm('Kirim recap hari ini sekarang?')) {
      return;
    }

    try {
      setTesting(true);
      const response = await sendWhatsAppRecap();

      if (response.success) {
        toast.success('Recap berhasil dikirim!');
        fetchSettings();
      }
    } catch (error) {
      const message = error.response?.data?.message || 'Gagal mengirim recap';
      toast.error(message);
    } finally {
      setTesting(false);
    }
  };

  if (loading) {
    return (
      <MainLayout>
        <Loading />
      </MainLayout>
    );
  }

  return (
    <MainLayout>
      <div className="space-y-6 sm:space-y-8">
        {/* Header */}
        <div>
          <h1 className="text-display-md sm:text-display-lg">WhatsApp Gateway</h1>
          <p className="mt-2 font-serif text-body">Konfigurasi notifikasi WhatsApp untuk recap absensi harian</p>
        </div>

        {/* Connection Status Card */}
        <Card>
          <div>
            <h2 className="text-display-sm mb-6 flex items-center">
              <MessageCircle size={24} className="mr-2 text-muted" />
              Status Koneksi
            </h2>

            {linkingState.step === 'idle' && !settings?.connected && (
              <div>
                <p className="font-serif text-body mb-4">WhatsApp belum terhubung. Hubungkan akun WhatsApp Anda untuk mulai mengirim notifikasi.</p>
                <div className="flex flex-col sm:flex-row gap-3">
                  <Button onClick={() => startLinking('qr')}>
                    <CheckCircle size={18} />
                    Link dengan QR Code
                  </Button>
                  <Button variant="secondary" onClick={() => startLinking('existing')}>
                    <Settings size={18} />
                    Link Account Existing
                  </Button>
                </div>
              </div>
            )}

            {linkingState.step === 'idle' && settings?.connected && (
              <div className="space-y-4">
                <div className="rounded-xl border-2 border-ink bg-surface-soft p-4">
                  <div className="flex items-center">
                    <CheckCircle className="text-success mr-3 shrink-0" size={24} />
                    <div className="flex-1 min-w-0">
                      {settings.account_phone ? (
                        <>
                          <p className="font-mono text-sm text-ink break-all">
                            {settings.account_phone}
                            {settings.account_unique_id && ` (${settings.account_unique_id})`}
                          </p>
                          <p className="font-mono text-xs text-muted mt-1">
                            Terhubung: {new Date(settings.connected_at).toLocaleString('id-ID')}
                          </p>
                        </>
                      ) : (
                        <>
                          <p className="font-serif text-body-strong">WhatsApp Terhubung</p>
                          <p className="font-serif text-sm text-warning mt-1">
                            ⚠️ Info akun tidak tersedia. Silakan disconnect dan link ulang.
                          </p>
                        </>
                      )}
                    </div>
                  </div>
                </div>

                <div className="flex flex-col sm:flex-row gap-3">
                  <Button variant="secondary" onClick={handleRelink} disabled={submitting}>
                    <RefreshCw size={18} />
                    Relink Account
                  </Button>
                  <Button variant="danger" onClick={handleDisconnect} disabled={submitting}>
                    <Trash2 size={18} />
                    Disconnect
                  </Button>
                </div>
              </div>
            )}

            {linkingState.step === 'entering_secret' && linkingState.linkMethod === 'qr' && (
              <div className="space-y-4">
                <Input
                  label="WhatsApp Gateway API Secret"
                  type="password"
                  value={linkingState.apiSecret}
                  onChange={(e) => setLinkingState({ ...linkingState, apiSecret: e.target.value })}
                  placeholder="Masukkan API Secret dari WhatsApp Gateway"
                  helperText="Dapatkan API Secret dari dashboard WhatsApp Gateway Anda"
                />
                <div className="flex flex-col sm:flex-row gap-3">
                  <Button onClick={handleCreateLink} disabled={submitting}>
                    {submitting ? 'Membuat QR Code...' : 'Buat QR Code'}
                  </Button>
                  <Button
                    variant="secondary"
                    onClick={() => setLinkingState({ step: 'idle', linkMethod: 'qr', qrImageUrl: null, token: null, apiSecret: '', uniqueId: '' })}
                  >
                    Batal
                  </Button>
                </div>
              </div>
            )}

            {linkingState.step === 'entering_secret' && linkingState.linkMethod === 'existing' && (
              <div className="space-y-4">
                <Input
                  label="WhatsApp Gateway API Secret"
                  type="password"
                  value={linkingState.apiSecret}
                  onChange={(e) => setLinkingState({ ...linkingState, apiSecret: e.target.value })}
                  placeholder="Masukkan API Secret dari WhatsApp Gateway"
                  helperText="Dapatkan API Secret dari dashboard WhatsApp Gateway Anda"
                />
                <Input
                  label="Unique ID"
                  type="text"
                  value={linkingState.uniqueId}
                  onChange={(e) => setLinkingState({ ...linkingState, uniqueId: e.target.value })}
                  placeholder="Masukkan Unique ID dari account WhatsApp yang sudah connected"
                  helperText="Contoh: 1765473230e4da3b7fbbce2345d7772b0674a318d5693af..."
                />
                <div className="flex flex-col sm:flex-row gap-3">
                  <Button onClick={handleLinkExisting} disabled={submitting}>
                    {submitting ? 'Menghubungkan...' : 'Hubungkan Account'}
                  </Button>
                  <Button
                    variant="secondary"
                    onClick={() => setLinkingState({ step: 'idle', linkMethod: 'qr', qrImageUrl: null, token: null, apiSecret: '', uniqueId: '' })}
                  >
                    Batal
                  </Button>
                </div>
              </div>
            )}

            {linkingState.step === 'showing_qr' && (
              <div className="text-center space-y-4">
                <p className="font-serif text-body-strong">Scan QR Code ini dengan WhatsApp Anda</p>
                {linkingState.qrImageUrl && (
                  <img
                    src={linkingState.qrImageUrl}
                    alt="QR Code"
                    className="mx-auto max-w-full rounded-xl border-2 border-ink"
                    style={{ maxWidth: '300px' }}
                  />
                )}
                <div className="flex items-center justify-center caption-uppercase">
                  <RefreshCw className="animate-spin mr-2" size={20} />
                  <span>Menunggu scan...</span>
                </div>
                <p className="font-serif text-sm text-muted">QR Code akan expired dalam 5 menit</p>
              </div>
            )}

            {linkingState.step === 'connected' && settings && (
              <div className="space-y-4">
                <div className="rounded-xl border-2 border-ink bg-surface-soft p-4">
                  <div className="flex items-center">
                    <CheckCircle className="text-success mr-3 shrink-0" size={24} />
                    <div className="flex-1 min-w-0">
                      {settings.account_phone ? (
                        <>
                          <p className="font-mono text-sm text-ink break-all">
                            {settings.account_phone}
                            {settings.account_unique_id && ` (${settings.account_unique_id})`}
                          </p>
                          <p className="font-mono text-xs text-muted mt-1">
                            Terhubung: {new Date(settings.connected_at).toLocaleString('id-ID')}
                          </p>
                        </>
                      ) : (
                        <>
                          <p className="font-serif text-body-strong">WhatsApp Terhubung</p>
                          <p className="font-serif text-sm text-warning mt-1">
                            ⚠️ Info akun tidak tersedia. Silakan disconnect dan link ulang.
                          </p>
                        </>
                      )}
                    </div>
                  </div>
                </div>

                <div className="flex flex-col sm:flex-row gap-3">
                  <Button variant="secondary" onClick={handleRelink} disabled={submitting}>
                    <RefreshCw size={18} />
                    Relink Account
                  </Button>
                  <Button variant="danger" onClick={handleDisconnect} disabled={submitting}>
                    <Trash2 size={18} />
                    Disconnect
                  </Button>
                </div>
              </div>
            )}
          </div>
        </Card>

        {/* Configuration Card - Only show if connected */}
        {settings?.connected && (
          <>
            <Card>
              <div>
                <h2 className="text-display-sm mb-6 flex items-center">
                  <Settings size={24} className="mr-2 text-muted" />
                  Konfigurasi Recap
                </h2>

                <form onSubmit={handleUpdateSettings} className="space-y-4">
                  <Input
                    label="Nomor Penerima"
                    type="text"
                    value={configForm.whatsapp_recipient_phone}
                    onChange={(e) => setConfigForm({ ...configForm, whatsapp_recipient_phone: e.target.value })}
                    placeholder="08123456789 atau 628123456789"
                    helperText="Nomor WhatsApp yang akan menerima recap harian"
                    required
                  />

                  <Input
                    label="Waktu Pengiriman"
                    type="time"
                    value={configForm.whatsapp_recap_time}
                    onChange={(e) => setConfigForm({ ...configForm, whatsapp_recap_time: e.target.value })}
                    helperText="Waktu pengiriman recap otomatis setiap hari"
                    required
                  />

                  <div className="flex items-center">
                    <input
                      type="checkbox"
                      id="recap_enabled"
                      checked={configForm.whatsapp_recap_enabled}
                      onChange={(e) => setConfigForm({ ...configForm, whatsapp_recap_enabled: e.target.checked })}
                      className="h-4 w-4 rounded border-2 border-ink accent-primary"
                    />
                    <label htmlFor="recap_enabled" className="ml-2 block font-serif text-sm text-body">
                      Aktifkan pengiriman recap otomatis
                    </label>
                  </div>

                  <Button type="submit" disabled={submitting}>
                    {submitting ? 'Menyimpan...' : 'Simpan Pengaturan'}
                  </Button>
                </form>

                {settings.last_sent_at && (
                  <div className="mt-4 pt-4 border-t border-hairline">
                    <p className="font-serif text-sm text-muted">
                      Recap terakhir dikirim: {new Date(settings.last_sent_at).toLocaleString('id-ID')}
                    </p>
                  </div>
                )}

                {settings.last_error && (
                  <div className="mt-4 rounded-xl border-2 border-error bg-error/5 p-3">
                    <div className="flex items-center">
                      <XCircle className="text-error mr-2 shrink-0" size={18} />
                      <p className="font-serif text-sm text-error">Error terakhir: {settings.last_error}</p>
                    </div>
                  </div>
                )}
              </div>
            </Card>

            {/* Testing & Preview Card */}
            <Card>
              <div>
                <h2 className="text-display-sm mb-6 flex items-center">
                  <Send size={24} className="mr-2 text-muted" />
                  Testing & Preview
                </h2>

                <div className="space-y-3">
                  <div className="flex flex-col sm:flex-row gap-3">
                    <Button onClick={handlePreviewRecap} disabled={previewing} variant="secondary">
                      <Eye size={18} />
                      {previewing ? 'Loading...' : 'Preview Recap Hari Ini'}
                    </Button>
                    <Button onClick={handleTestSend} disabled={testing || !configForm.whatsapp_recipient_phone}>
                      <Send size={18} />
                      {testing ? 'Mengirim...' : 'Kirim Pesan Test'}
                    </Button>
                  </div>

                  <Button
                    onClick={handleSendRecap}
                    disabled={testing || !settings.can_send_recap}
                    className="w-full"
                  >
                    {testing ? 'Mengirim...' : 'Kirim Recap Sekarang'}
                  </Button>

                  {!settings.can_send_recap && (
                    <p className="font-serif text-sm text-warning">
                      Lengkapi konfigurasi dan aktifkan recap untuk mengirim pesan
                    </p>
                  )}
                </div>
              </div>
            </Card>
          </>
        )}
      </div>

      {/* Preview Modal */}
      {showPreviewModal && (
        <div className="fixed inset-0 bg-ink/50 flex items-center justify-center z-50 p-4">
          <div className="bg-surface-card border-2 border-ink rounded-card shadow-brutal-lg max-w-2xl w-full max-h-[80vh] overflow-y-auto">
            <div className="p-4 sm:p-6">
              <h3 className="text-display-sm mb-4">Preview Recap Hari Ini</h3>
              <pre className="whitespace-pre-wrap text-sm bg-surface-soft p-4 rounded-xl border-2 border-ink font-mono text-body">
                {previewMessage}
              </pre>
              <div className="mt-4 flex justify-end">
                <Button onClick={() => setShowPreviewModal(false)}>Tutup</Button>
              </div>
            </div>
          </div>
        </div>
      )}
    </MainLayout>
  );
};
