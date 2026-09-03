'use client';

import React, { useState, useEffect } from 'react';
import { useAuth } from '@/lib/auth';
import api from '@/lib/api';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';

export default function SettingsPage() {
  const { user, mutate } = useAuth();
  const [profileData, setProfileData] = useState({
    name: '',
    phone: '',
    company_name: '',
    address: '',
  });
  const [passwordData, setPasswordData] = useState({
    current_password: '',
    new_password: '',
    new_password_confirmation: '',
  });
  
  const [isSavingProfile, setIsSavingProfile] = useState(false);
  const [isSavingPassword, setIsSavingPassword] = useState(false);
  const [profileMsg, setProfileMsg] = useState({ type: '', text: '' });
  const [passwordMsg, setPasswordMsg] = useState({ type: '', text: '' });

  useEffect(() => {
    if (user) {
      setProfileData({
        name: user.name || '',
        phone: user.phone || '',
        company_name: user.company_name || '',
        address: user.address || '',
      });
    }
  }, [user]);

  const handleProfileSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsSavingProfile(true);
    setProfileMsg({ type: '', text: '' });
    try {
      // Assuming a patch endpoint exists
      await api.patch('/api/auth/user', profileData);
      mutate();
      setProfileMsg({ type: 'success', text: 'Profile updated successfully.' });
    } catch (error: any) {
      setProfileMsg({ type: 'error', text: error.response?.data?.message || 'Failed to update profile.' });
    } finally {
      setIsSavingProfile(false);
    }
  };

  const handlePasswordSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    
    if (passwordData.new_password !== passwordData.new_password_confirmation) {
      setPasswordMsg({ type: 'error', text: 'New passwords do not match.' });
      return;
    }

    setIsSavingPassword(true);
    setPasswordMsg({ type: '', text: '' });
    try {
      await api.post('/api/auth/password', passwordData);
      setPasswordMsg({ type: 'success', text: 'Password updated successfully.' });
      setPasswordData({ current_password: '', new_password: '', new_password_confirmation: '' });
    } catch (error: any) {
      setPasswordMsg({ type: 'error', text: error.response?.data?.message || 'Failed to change password.' });
    } finally {
      setIsSavingPassword(false);
    }
  };

  if (!user) return <div className="p-8">Loading...</div>;

  return (
    <div className="max-w-4xl mx-auto p-4 md:p-8 space-y-8">
      <h1 className="text-3xl font-bold text-gray-900 dark:text-white">Account Settings</h1>

      <Card className="p-6">
        <h2 className="text-xl font-semibold mb-4 text-gray-900 dark:text-white">Profile Information</h2>
        {profileMsg.text && (
          <div className={`p-3 mb-4 rounded text-sm ${profileMsg.type === 'success' ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'}`}>
            {profileMsg.text}
          </div>
        )}
        <form onSubmit={handleProfileSubmit} className="space-y-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Email Address</label>
            <Input type="email" value={user.email} disabled className="mt-1 bg-gray-50 text-gray-500" />
            <p className="text-xs text-gray-500 mt-1">Email address cannot be changed.</p>
          </div>
          
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Full Name</label>
              <Input 
                type="text" 
                value={profileData.name} 
                onChange={(e) => setProfileData({...profileData, name: e.target.value})} 
                className="mt-1" 
                required 
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Phone</label>
              <Input 
                type="text" 
                value={profileData.phone} 
                onChange={(e) => setProfileData({...profileData, phone: e.target.value})} 
                className="mt-1" 
              />
            </div>
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Company Name</label>
            <Input 
              type="text" 
              value={profileData.company_name} 
              onChange={(e) => setProfileData({...profileData, company_name: e.target.value})} 
              className="mt-1" 
            />
          </div>

          <div className="pt-2">
            <Button type="submit" disabled={isSavingProfile}>
              {isSavingProfile ? 'Saving...' : 'Save Profile'}
            </Button>
          </div>
        </form>
      </Card>

      <Card className="p-6">
        <h2 className="text-xl font-semibold mb-4 text-gray-900 dark:text-white">Change Password</h2>
        {passwordMsg.text && (
          <div className={`p-3 mb-4 rounded text-sm ${passwordMsg.type === 'success' ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'}`}>
            {passwordMsg.text}
          </div>
        )}
        <form onSubmit={handlePasswordSubmit} className="space-y-4 max-w-md">
          <div>
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Current Password</label>
            <Input 
              type="password" 
              value={passwordData.current_password} 
              onChange={(e) => setPasswordData({...passwordData, current_password: e.target.value})} 
              className="mt-1" 
              required 
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">New Password</label>
            <Input 
              type="password" 
              value={passwordData.new_password} 
              onChange={(e) => setPasswordData({...passwordData, new_password: e.target.value})} 
              className="mt-1" 
              required 
              minLength={8}
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Confirm New Password</label>
            <Input 
              type="password" 
              value={passwordData.new_password_confirmation} 
              onChange={(e) => setPasswordData({...passwordData, new_password_confirmation: e.target.value})} 
              className="mt-1" 
              required 
            />
          </div>
          <div className="pt-2">
            <Button type="submit" disabled={isSavingPassword}>
              {isSavingPassword ? 'Updating...' : 'Update Password'}
            </Button>
          </div>
        </form>
      </Card>

      <Card className="p-6 border-red-200 bg-red-50/50 dark:bg-red-900/10 dark:border-red-900/50">
        <h2 className="text-xl font-semibold mb-2 text-red-700 dark:text-red-400">Danger Zone</h2>
        <p className="text-sm text-gray-600 dark:text-gray-400 mb-4">
          Once you delete your account, there is no going back. Please be certain.
        </p>
        <Button variant="destructive" onClick={() => alert('Account deletion would happen here.')}>
          Delete Account
        </Button>
      </Card>
    </div>
  );
}
