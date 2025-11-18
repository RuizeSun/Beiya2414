import tkinter as tk
from tkinter import ttk, messagebox, filedialog
import requests
import os
import threading
# from PIL import Image, ImageTk # 未使用到，可以注释或删除

# --- 硬编码配置 ---
API_BASE_URL = "http://localhost/system"
LOGIN_URL = f"{API_BASE_URL}/screen-login.php"
HOMEWORK_SUBMIT_URL = f"{API_BASE_URL}/screen-homework.php"
HOMEWORK_LIST_URL = f"{HOMEWORK_SUBMIT_URL}?action=get_homework_list" # 新增：获取作业列表的 API 路径
# DEFAULT_HOMEWORK_ID = "12345" # 已改为动态获取，此硬编码值不再需要
# --- 全局状态 ---
app_state = {
    "screen_id": None,
    "auth_token": None, # 存储 token 值，用于构建 screen-token cookie
    "current_frame": None,
}

class MainApp(tk.Tk):
    """主应用窗口，负责框架切换和状态管理。"""
    def __init__(self):
        super().__init__()
        self.title("班级大屏管理工具 (Python GUI)")
        self.geometry("600x450")
        self.resizable(False, False) # 固定窗口大小

        # 创建一个容器，用于堆叠不同视图
        self.container = ttk.Frame(self)
        self.container.pack(side="top", fill="both", expand=True)
        self.container.grid_rowconfigure(0, weight=1)
        self.container.grid_columnconfigure(0, weight=1)

        self.frames = {}
        self.create_frames()
        self.show_frame("LoginFrame")

    def create_frames(self):
        """初始化所有界面框架。"""
        # 登入界面
        login_frame = LoginFrame(self.container, self)
        self.frames["LoginFrame"] = login_frame
        login_frame.grid(row=0, column=0, sticky="nsew")
        
        # 作业提交界面
        homework_frame = HomeworkFrame(self.container, self)
        self.frames["HomeworkFrame"] = homework_frame
        homework_frame.grid(row=0, column=0, sticky="nsew")

    def show_frame(self, page_name):
        """显示指定的界面框架。"""
        frame = self.frames[page_name]
        frame.tkraise()
        app_state["current_frame"] = page_name
        if page_name == "HomeworkFrame":
            frame.update_title()
            # 确保每次切换到作业界面时都尝试重新加载作业列表
            frame.fetch_homework_list()

    def set_auth_state(self, screen_id, token):
        """设置登入状态。"""
        app_state["screen_id"] = screen_id
        app_state["auth_token"] = token


class LoginFrame(ttk.Frame):
    """登入界面，用于获取大屏 ID 和密码。"""
    def __init__(self, parent, controller):
        super().__init__(parent, padding="20 40 20 20")
        self.controller = controller
        
        # 居中布局
        self.columnconfigure(0, weight=1)
        self.columnconfigure(1, weight=1)

        # 标题
        ttk.Label(self, text="班级大屏登入", font=("Helvetica", 16, "bold")).grid(row=0, column=0, columnspan=2, pady=10)
        ttk.Label(self, text="请输入大屏 ID 和密码", font=("Helvetica", 10)).grid(row=1, column=0, columnspan=2, pady=(0, 20))

        # 大屏 ID 输入
        ttk.Label(self, text="大屏 ID:", width=15, anchor="e").grid(row=2, column=0, padx=5, pady=5, sticky="w")
        self.screen_id_var = tk.StringVar(value="123") # 预填一个示例 ID 方便测试
        self.screen_id_entry = ttk.Entry(self, textvariable=self.screen_id_var, width=30)
        self.screen_id_entry.grid(row=2, column=1, padx=5, pady=5, sticky="ew")

        # 密码输入
        ttk.Label(self, text="密码:", width=15, anchor="e").grid(row=3, column=0, padx=5, pady=5, sticky="w")
        self.password_var = tk.StringVar(value="password") # 预填一个示例密码
        self.password_entry = ttk.Entry(self, textvariable=self.password_var, show="*", width=30)
        self.password_entry.grid(row=3, column=1, padx=5, pady=5, sticky="ew")

        # 登入按钮
        self.login_button = ttk.Button(self, text="登入", command=self.start_login_thread)
        self.login_button.grid(row=4, column=0, columnspan=2, pady=20, sticky="ew")

        # 消息显示
        self.message_label = ttk.Label(self, text="", foreground="red")
        self.message_label.grid(row=5, column=0, columnspan=2, sticky="ew")

    def start_login_thread(self):
        """在新线程中执行登入操作，防止阻塞 GUI。"""
        self.message_label.config(text="正在验证...", foreground="blue")
        self.login_button.config(state=tk.DISABLED, text="验证中...")
        threading.Thread(target=self.login_action, daemon=True).start()

    def login_action(self):
        """执行 API 登入逻辑。"""
        screen_id = self.screen_id_var.get().strip()
        password = self.password_var.get().strip()

        if not screen_id or not password:
            self.update_gui_after_login("请填写大屏 ID 和密码。", "red", tk.NORMAL, "登入")
            return

        try:
            # 模拟原前端的 JSON POST 请求
            response = requests.post(
                LOGIN_URL,
                json={"screenId": screen_id, "password": password},
                timeout=5
            )
            data = response.json()

            if response.status_code == 200 and data.get("status") == "success":
                # 登入成功，设置状态并切换到作业管理界面
                # data.get("token") 包含用于 screen-token cookie 的值
                self.controller.set_auth_state(screen_id, data.get("token")) 
                self.update_gui_after_login(f"登入成功! ID: {screen_id}", "green", tk.NORMAL, "登入")
                self.after(1000, lambda: self.controller.show_frame("HomeworkFrame"))
            else:
                # 登入失败
                msg = data.get("message", "未知错误")
                self.update_gui_after_login(f"登入失败: {msg}", "red", tk.NORMAL, "登入")
                
        except requests.exceptions.RequestException as e:
            msg = f"网络错误或服务器无响应: {e}"
            self.update_gui_after_login(msg, "red", tk.NORMAL, "登入")
        except requests.exceptions.JSONDecodeError:
            msg = f"API 响应非 JSON 格式: {response.text[:100]}..."
            self.update_gui_after_login(msg, "red", tk.NORMAL, "登入")
        except Exception as e:
            msg = f"发生意外错误: {e}"
            self.update_gui_after_login(msg, "red", tk.NORMAL, "登入")

    def update_gui_after_login(self, msg, color, button_state, button_text):
        """线程安全地更新 GUI 元素。"""
        self.after(0, lambda: self.message_label.config(text=msg, foreground=color))
        self.after(0, lambda: self.login_button.config(state=button_state, text=button_text))


class HomeworkFrame(ttk.Frame):
    """作业管理界面，模拟提交功能。"""
    def __init__(self, parent, controller):
        super().__init__(parent, padding="20")
        self.controller = controller

        # 内部状态
        self.homework_data = [] # Stores list of dicts: [{"id": "...", "name": "..."}]
        self.selected_file_path = None
        
        # 标题 (会动态更新)
        self.title_label = ttk.Label(self, text="大屏作业管理", font=("Helvetica", 14, "bold"))
        self.title_label.pack(pady=10)

        # 提交作业区域
        submit_frame = ttk.LabelFrame(self, text="提交作业", padding="10")
        submit_frame.pack(pady=15, fill="x")

        # 提交控件
        self.create_submission_widgets(submit_frame)
        
        # 返回登入按钮
        ttk.Button(self, text="<< 返回登入", command=lambda: controller.show_frame("LoginFrame")).pack(pady=10)

    def update_title(self):
        """根据当前登入状态更新标题。"""
        screen_id = app_state["screen_id"] or "未登入"
        self.title_label.config(text=f"大屏作业管理 - ID: {screen_id}")

    def create_submission_widgets(self, parent):
        """创建作业提交的输入控件。"""
        
        grid_frame = ttk.Frame(parent)
        grid_frame.pack(fill="x", padx=5, pady=5)
        grid_frame.columnconfigure(1, weight=1)
        grid_frame.columnconfigure(2, weight=0)

        # 1. 作业 ID (改为下拉菜单)
        ttk.Label(grid_frame, text="选择作业:", width=15).grid(row=0, column=0, sticky="w", pady=5)
        self.homework_name_var = tk.StringVar(value="正在加载作业列表...")
        self.homework_id_var = tk.StringVar(value="") # 存储选中的实际 ID
        
        self.homework_dropdown = ttk.Combobox(
            grid_frame, 
            textvariable=self.homework_name_var, 
            state="readonly", # 用户不可手动输入
            width=30
        )
        self.homework_dropdown.grid(row=0, column=1, columnspan=2, sticky="ew", padx=5, pady=5)
        self.homework_dropdown.bind("<<ComboboxSelected>>", self.on_homework_selected)


        # 2. 学生 ID (需要用户输入)
        ttk.Label(grid_frame, text="学生 ID:", width=15).grid(row=1, column=0, sticky="w", pady=5)
        self.student_id_var = tk.StringVar()
        ttk.Entry(grid_frame, textvariable=self.student_id_var).grid(row=1, column=1, columnspan=2, sticky="ew", padx=5, pady=5)

        # 3. 文件选择
        ttk.Label(grid_frame, text="选择图片文件:", width=15).grid(row=2, column=0, sticky="w", pady=5)
        self.file_path_var = tk.StringVar(value="未选择文件")
        ttk.Label(grid_frame, textvariable=self.file_path_var).grid(row=2, column=1, sticky="w", padx=5, pady=5)
        ttk.Button(grid_frame, text="浏览", command=self.select_file, width=6).grid(row=2, column=2, padx=5, pady=5)

        # 4. 提交按钮
        self.submit_button = ttk.Button(parent, text="上传并提交作业", command=self.start_submit_thread, state=tk.DISABLED)
        self.submit_button.pack(pady=15, fill="x")

        # 5. 消息显示
        self.message_label = ttk.Label(parent, text="", foreground="black")
        self.message_label.pack(fill="x", pady=5)
        
        # 6. 提示信息
        ttk.Label(parent, text="注：此 Python GUI 版本为简化实现，不包含原前端的图像压缩逻辑。", 
                  font=("Helvetica", 8, "italic"), foreground="gray").pack(pady=5)
        
        # 首次加载时调用
        self.fetch_homework_list()

    def on_homework_selected(self, event):
        """处理作业下拉菜单选择事件，更新实际的 homework_id_var。"""
        selected_name = self.homework_name_var.get()
        # 根据显示的 name 查找对应的 ID
        selected_item = next((item for item in self.homework_data if item.get('name') == selected_name), None)
        
        if selected_item:
            self.homework_id_var.set(selected_item.get('id', ''))
            self.message_label.config(text=f"已选择作业ID: {selected_item.get('id', 'N/A')}", foreground="black")
            self.submit_button.config(state=tk.NORMAL)
        else:
            self.homework_id_var.set("")
            self.message_label.config(text="请重新选择有效的作业。", foreground="red")
            self.submit_button.config(state=tk.DISABLED)


    def fetch_homework_list(self):
        """在新线程中执行获取作业列表的 API 调用。"""
        # 如果 auth_token 丢失，则不尝试获取
        if not app_state["auth_token"]:
             self.message_label.config(text="未登入，请先登入。", foreground="red")
             return

        # 如果已经有数据，先不重新加载
        if self.homework_data:
            return

        self.submit_button.config(state=tk.DISABLED)
        self.homework_name_var.set("正在从服务器获取作业列表...")
        self.message_label.config(text="正在从服务器获取作业列表...", foreground="blue")
        threading.Thread(target=self._fetch_homework_list_action, daemon=True).start()

    def _fetch_homework_list_action(self):
        """执行 API 调用并更新下拉菜单。"""
        try:
            # 构造 cookies，键名为 screen-token
            cookies = {}
            token = app_state["auth_token"]
            if token:
                cookies['screen-token'] = token
                
            response = requests.get(
                HOMEWORK_LIST_URL,
                cookies=cookies, # 使用 cookies 参数传递认证信息
                timeout=10
            )
            data = response.json()

            if response.status_code == 200 and data.get("status") == "success" and data.get("homeworks"):
                self.homework_data = data["homeworks"]
                self.after(0, self.populate_homework_dropdown)
            else:
                msg = data.get("message", "未能获取作业列表。请确认服务器运行正常或已登入。")
                self.update_gui_after_submit(f"加载失败: {msg}", "red", tk.DISABLED, "上传并提交作业")

        except requests.exceptions.RequestException as e:
            msg = f"网络错误或服务器无响应: {e}"
            self.update_gui_after_submit(msg, "red", tk.DISABLED, "上传并提交作业")
        except Exception as e:
            msg = f"解析错误: {e}"
            self.update_gui_after_submit(msg, "red", tk.DISABLED, "上传并提交作业")

    def populate_homework_dropdown(self):
        """线程安全地更新作业下拉菜单的内容。"""
        if self.homework_data:
            # 假设 homework_data 结构为 [{"id": "...", "name": "..."}]
            homework_names = [item.get('name', f"ID: {item.get('id', '未知')}") for item in self.homework_data]
            self.homework_dropdown['values'] = homework_names
            
            # 自动选择第一个作业
            self.homework_dropdown.current(0)
            self.on_homework_selected(None) # 触发选择事件来设置 homework_id_var
            
            self.message_label.config(text="作业列表加载成功。", foreground="green")
            self.submit_button.config(state=tk.NORMAL)
        else:
            self.homework_name_var.set("无可用作业")
            self.homework_dropdown['values'] = ["无可用作业"]
            self.submit_button.config(state=tk.DISABLED)
            self.message_label.config(text="无可用作业。", foreground="red")


    def select_file(self):
        """打开文件对话框让用户选择图片文件。"""
        file_path = filedialog.askopenfilename(
            title="选择作业图片文件",
            filetypes=[("图片文件", "*.jpg *.jpeg *.png")]
        )
        if file_path:
            self.file_path_var.set(os.path.basename(file_path))
            self.selected_file_path = file_path
        else:
            self.file_path_var.set("未选择文件")
            self.selected_file_path = None

    def start_submit_thread(self):
        """在新线程中执行提交操作。"""
        # 再次检查是否有选中的作业 ID
        if not self.homework_id_var.get():
             self.message_label.config(text="请选择一个作业 ID。", foreground="red")
             return

        self.message_label.config(text="正在上传...", foreground="blue")
        self.submit_button.config(state=tk.DISABLED, text="正在上传...")
        threading.Thread(target=self.submit_action, daemon=True).start()

    def submit_action(self):
        """执行 API 作业提交逻辑。"""
        student_id = self.student_id_var.get().strip()
        homework_id = self.homework_id_var.get().strip()
        file_path = getattr(self, 'selected_file_path', None)

        if not student_id:
            self.update_gui_after_submit("请填写学生 ID。", "red", tk.NORMAL, "上传并提交作业")
            return
        
        if not homework_id:
            self.update_gui_after_submit("请选择作业 ID。", "red", tk.NORMAL, "上传并提交作业")
            return
        
        if not file_path or not os.path.exists(file_path):
            self.update_gui_after_submit("请选择一个有效的图片文件。", "red", tk.NORMAL, "上传并提交作业")
            return
        
        # 构造 cookies，键名为 screen-token
        cookies = {}
        token = app_state["auth_token"]
        if token:
            cookies['screen-token'] = token

        try:
            files = {
                'submission_file': (
                    os.path.basename(file_path), # 使用真实文件名
                    open(file_path, 'rb'), 
                    'image/jpeg' # 假定为 jpeg
                )
            }
            data = {
                'student_id': student_id,
                'homework_id': homework_id,
                'screen_id': app_state["screen_id"] or ""
            }
            
            response = requests.post(
                f"{HOMEWORK_SUBMIT_URL}?action=submit_homework",
                data=data,
                files=files,
                cookies=cookies, # 使用 cookies 参数传递认证信息
                timeout=30 
            )
            
            try:
                result = response.json()
            except requests.JSONDecodeError:
                self.update_gui_after_submit(
                    f"服务器返回非 JSON 错误 ({response.status_code})。内容片段: {response.text[:100]}...",
                    "red", tk.NORMAL, "上传并提交作业"
                )
                return

            if response.status_code == 200 and result.get("status") == "success":
                msg = result.get("message", "作业提交/更新成功！")
                self.update_gui_after_submit(msg, "green", tk.NORMAL, "上传并提交作业")
                # 清空学生ID和文件路径
                self.after(0, lambda: self.student_id_var.set(""))
                self.after(0, lambda: self.file_path_var.set("未选择文件"))
                self.selected_file_path = None
            else:
                msg = result.get("message", "提交失败，请检查数据。")
                self.update_gui_after_submit(f"提交失败: {msg}", "red", tk.NORMAL, "上传并提交作业")

        except requests.exceptions.RequestException as e:
            msg = f"网络错误或服务器无响应: {e}"
            self.update_gui_after_submit(msg, "red", tk.NORMAL, "上传并提交作业")
            
        except Exception as e:
            msg = f"发生意外错误: {e}"
            self.update_gui_after_submit(msg, "red", tk.NORMAL, "上传并提交作业")

    def update_gui_after_submit(self, msg, color, button_state, button_text):
        """线程安全地更新 GUI 元素。"""
        self.after(0, lambda: self.message_label.config(text=msg, foreground=color))
        self.after(0, lambda: self.submit_button.config(state=button_state, text=button_text))


if __name__ == "__main__":
    try:
        import requests
        app = MainApp()
        app.mainloop()
    except ImportError:
        root = tk.Tk()
        root.withdraw()
        # 提示用户安装所需的依赖
        messagebox.showerror(
            "缺少依赖", 
            "本程序需要 'requests' 库来进行 API 调用。\n请使用 'pip install requests' 命令安装依赖后再次运行。"
        )
        root.destroy()
    except Exception as e:
        root = tk.Tk()
        root.withdraw()
        messagebox.showerror("启动错误", f"程序启动时发生错误: {e}")
        root.destroy()