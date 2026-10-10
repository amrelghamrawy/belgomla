import type { ApiErrorCode } from "@/lib/api/errors";

// Every Arabic string the UI shows (rules/03 §5). No i18n framework in V1: Arabic only.

/**
 * A fallback message per error code. The backend's envelope message is already Arabic and more specific,
 * so `messageFor()` prefers it; these cover responses without an envelope and codes whose server text is
 * generic. `Record<ApiErrorCode, string>` makes a new code in the OpenAPI spec a type error until it is added.
 */
const errors: Record<ApiErrorCode, string> = {
  address_locked: "لا يمكن تعديل العنوان بعد تأكيد الطلب",
  already_voided: "تم إلغاء هذا التحصيل من قبل",
  blocked: "تم إيقاف حسابك، تواصل مع المتجر",
  cannot_disable_self: "لا يمكنك إيقاف حسابك بنفسك",
  cart_empty: "السلة فارغة",
  cart_line_invalid: "بعض أصناف السلة لم تعد متاحة بالكمية المطلوبة",
  category_cycle: "لا يمكن نقل القسم داخل أحد أقسامه الفرعية",
  checkout_failed: "تعذر إتمام الطلب، راجع السلة",
  cover_price_required: "سعر الغلاف مطلوب",
  csrf_token_mismatch: "انتهت صلاحية الجلسة، أعد تحميل الصفحة",
  duplicate_code: "الكود مستخدم من قبل",
  duplicate_key: "المفتاح مستخدم من قبل",
  duplicate_label: "الاسم مستخدم من قبل",
  duplicate_name: "الاسم مستخدم من قبل",
  duplicate_relation: "هذا الربط موجود من قبل",
  email_taken: "البريد الإلكتروني مستخدم من قبل",
  exceeds_balance: "المبلغ أكبر من المتبقي على الطلب",
  field_type_locked: "لا يمكن تغيير نوع الحقل بعد استخدامه",
  forbidden: "غير مسموح بهذا الإجراء",
  gallery_full: "وصلت للحد الأقصى من الصور",
  has_collections: "لا يمكن إلغاء طلب عليه تحصيلات",
  http_error: "حدث خطأ، حاول مرة أخرى",
  idempotency_in_progress: "الطلب قيد التنفيذ، انتظر لحظة",
  idempotency_key_reused: "تم إرسال هذا الطلب ببيانات مختلفة",
  in_use: "لا يمكن الحذف لأنه مستخدم",
  insufficient_stock: "الكمية المطلوبة غير متاحة",
  inventory_below_reserved: "لا يمكن تقليل المخزون عن الكمية المحجوزة",
  invalid_credentials: "بيانات الدخول غير صحيحة",
  invalid_idempotency_key: "مفتاح الطلب غير صالح",
  invalid_phone: "رقم الهاتف غير صالح",
  invalid_subdomain: "اسم النطاق غير صالح",
  invalid_tiers: "شرائح السعر غير صحيحة",
  invalid_transition: "لا يمكن تنفيذ هذا الإجراء في الحالة الحالية",
  invalid_vertical: "نوع النشاط غير صالح",
  invited: "أكمل التسجيل من رابط الدعوة أولًا",
  last_active_user: "يجب أن يبقى مستخدم نشط واحد على الأقل",
  network_error: "تعذر الاتصال، تأكد من الإنترنت وحاول مرة أخرى",
  not_found: "العنصر غير موجود",
  order_cancelled: "الطلب ملغي",
  otp_attempts_exceeded: "تجاوزت عدد المحاولات، اطلب كودًا جديدًا",
  otp_expired: "انتهت صلاحية الكود، اطلب كودًا جديدًا",
  otp_invalid: "الكود غير صحيح",
  pending_approval: "حسابك في انتظار موافقة المتجر",
  pending_verification: "أكد رقم هاتفك أولًا",
  phone_not_verified: "أكد رقم هاتفك أولًا",
  phone_taken: "رقم الهاتف مسجل من قبل",
  prices_hidden: "سجل الدخول لرؤية الأسعار",
  rate_limited: "محاولات كثيرة، حاول لاحقًا",
  reserved_subdomain: "اسم النطاق محجوز",
  server_error: "حدث خطأ غير متوقع، حاول مرة أخرى",
  stateful_request_required: "افتح الموقع من رابط المتجر وحاول مرة أخرى",
  store_unavailable: "المتجر غير متاح حاليًا",
  subdomain_taken: "اسم النطاق مستخدم من قبل",
  tenant_suspended: "تم إيقاف المتجر، تواصل مع الدعم",
  unauthenticated: "يجب تسجيل الدخول",
  use_invitation: "لديك دعوة من المتجر، أكمل التسجيل من رابط الدعوة",
  user_disabled: "تم إيقاف حسابك، تواصل مع مالك المتجر",
  validation_failed: "راجع البيانات المدخلة",
};

export const ar = {
  appName: "بالجملة",
  appDescription: "متجر الجملة الخاص بك",
  currency: "ج.م",
  loading: "جاري التحميل…",
  retry: "حاول مرة أخرى",
  errors,
  roles: {
    owner: "مالك",
  } as Record<string, string>,
  pages: {
    notFoundTitle: "الصفحة غير موجودة",
    notFoundBody: "الرابط الذي فتحته غير صحيح أو تم حذفه.",
    backHome: "العودة للرئيسية",
    errorTitle: "حدث خطأ",
    storeUnavailableTitle: "المتجر غير متاح",
    storeUnavailableBody: "المتجر متوقف مؤقتًا. حاول مرة أخرى لاحقًا.",
    storeNotFoundTitle: "المتجر غير موجود",
  },
  store: {
    welcome: "أهلًا بك في",
    login: "تسجيل الدخول",
    comingSoon: "المنتجات قريبًا",
  },
  auth: {
    email: "البريد الإلكتروني",
    phone: "رقم الهاتف",
    password: "كلمة المرور",
    submitLogin: "دخول",
    submitting: "جاري الدخول…",
    logout: "تسجيل الخروج",
    dashboardLoginTitle: "دخول لوحة التحكم",
    customerLoginTitle: "تسجيل دخول العملاء",
  },
  dashboard: {
    title: "لوحة التحكم",
    signedInAs: "مسجل الدخول باسم",
    role: "الصلاحية",
    store: "المتجر",
  },
  validation: {
    required: "هذا الحقل مطلوب",
    email: "اكتب بريدًا إلكترونيًا صحيحًا",
    phone: "اكتب رقم هاتف مصري صحيح",
  },
};

/** The text to show for a failed call: the backend's Arabic message, or the fallback for its code. */
export function messageFor(error: unknown): string {
  if (typeof error === "object" && error !== null && "code" in error && typeof error.code === "string") {
    const code = error.code as ApiErrorCode;
    const genericOnServer = code === "server_error" || code === "http_error";
    const serverMessage = "message" in error && typeof error.message === "string" ? error.message : "";

    return genericOnServer || serverMessage === "" ? (errors[code] ?? errors.server_error) : serverMessage;
  }

  return errors.server_error;
}
