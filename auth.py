"""
PRA Auth - Cloud Compatible
"""
import streamlit as st
import yaml
import hashlib
from typing import Dict, Optional
from dataclasses import dataclass

@dataclass
class User:
    username: str
    name: str
    company: str
    role: str
    password_hash: str
    email: Optional[str] = None
    active: bool = True

class AuthManager:
    def __init__(self):
        self.users = {}
        self._load_users()
    
    def _hash_password(self, password: str) -> str:
        return hashlib.sha256(password.encode()).hexdigest()
    
    def _load_users(self):
        # Default users - CLOUD SAFE
        self.users = {
            'admin': User(
                username='admin',
                name='PRA Admin',
                company='PRA Construction',
                role='admin',
                password_hash=self._hash_password('Pra2026!')
            ),
            'manager': User(
                username='manager',
                name='Site Manager',
                company='PRA Construction',
                role='manager', 
                password_hash=self._hash_password('Site2026!')
            ),
            'viewer': User(
                username='viewer',
                name='Viewer',
                company='PRA Construction',
                role='viewer',
                password_hash=self._hash_password('View2026!')
            )
        }
    
    def login(self, username: str, password: str) -> bool:
        user = self.users.get(username)
        if user and user.active:
            return self._hash_password(password) == user.password_hash
        return False
    
    def login_ui(self):
        return st.session_state.get('login_ui', False)
    
    def logout(self):
        for key in st.session_state:
            del st.session_state[key]
    
    def get_current_user(self):
        if st.session_state.get('authenticated'):
            return self.users.get(st.session_state['username'])
        return None
    
    def can_access(self, required_role: str, user_role: str) -> bool:
        hierarchy = {'admin': 3, 'manager': 2, 'viewer': 1}
        return hierarchy.get(user_role, 0) >= hierarchy.get(required_role, 0)

# Global
auth_manager = AuthManager()
